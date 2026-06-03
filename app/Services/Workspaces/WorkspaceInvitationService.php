<?php

namespace App\Services\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Enums\WorkspaceInvitationGrantMode;
use App\Enums\WorkspaceInvitationStatus;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceInvitationProjectGrant;
use App\Models\WorkspaceInvitationResourceGrant;
use App\Models\WorkspaceInvitationVaultKey;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Workspace invitation lifecycle — consolidated spec.
 *
 * `create()` stages workspace membership + per-project access + per-
 * resource grants + wrapped vault keys in a single transaction. Each
 * project entry is authz-gated against the inviter's own rights
 * (can't grant what you can't hold).
 *
 * `accept()` re-validates every staged grant, applies what's still
 * valid, and emits inline `warnings` for anything that's drifted
 * (vault rotated, project deleted, workspace-project link broken).
 * The invitee gets workspace membership and every grant that still
 * holds; a single stale grant doesn't roll back the whole accept.
 */
class WorkspaceInvitationService
{
    public function __construct(private readonly PermissionService $perms) {}

    /**
     * @param  array<int, array{
     *     project_id:int,
     *     mode:string,
     *     project_role?:?string,
     *     vault_keys?:array<int, array{vault_id:int,encrypted_key:string,key_version:int}>,
     *     resources?:array<int, array{type:string,id:int,role:string,encrypted_key?:?string,key_version?:?int}>
     * }>  $projects
     *
     * @throws ValidationException
     */
    public function create(
        Organisation $workspace,
        User $inviter,
        string $email,
        OrganisationRole $role,
        array $projects = [],
    ): WorkspaceInvitation {
        $normalisedEmail = strtolower(trim($email));

        $existing = WorkspaceInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->whereRaw('lower(invitee_email) = ?', [$normalisedEmail])
            ->where('status', WorkspaceInvitationStatus::Pending->value)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => ['A pending invitation already exists for this email on this workspace.'],
            ])->status(409);
        }

        $existingMember = OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->whereIn('user_id', function ($q) use ($normalisedEmail): void {
                $q->select('id')->from('users')->whereRaw('lower(email) = ?', [$normalisedEmail]);
            })
            ->exists();

        if ($existingMember) {
            throw ValidationException::withMessages([
                'email' => ['This user is already a member of the workspace.'],
            ])->status(409);
        }

        $this->assertSeatAvailable($workspace, $pendingCountsTowardCap = true);

        // Authz + structural validation per project entry. Fails fast
        // (outside the transaction) because bad input from the inviter
        // shouldn't touch the DB.
        $this->validateProjectGrants($workspace, $inviter, $projects);

        $invitee = User::query()->whereRaw('lower(email) = ?', [$normalisedEmail])->first();

        return DB::transaction(function () use ($workspace, $inviter, $email, $invitee, $role, $projects): WorkspaceInvitation {
            $invitation = WorkspaceInvitation::create([
                'workspace_id' => $workspace->id,
                'inviter_id' => $inviter->id,
                'invitee_email' => $email,
                'invitee_id' => $invitee?->id,
                'role' => $role->value,
                'token' => Str::random(48),
                'status' => WorkspaceInvitationStatus::Pending->value,
                'expires_at' => Carbon::now()->addDays(14),
            ]);

            foreach ($projects as $project) {
                $this->stageProjectGrant($invitation, $project);
            }

            return $invitation;
        });
    }

    /**
     * Apply the invitation. Membership insert is strict (seat cap
     * race → 409 workspace_full). Staged project grants are best-
     * effort: each one validates and applies independently; failures
     * become inline warnings so the invitee keeps everything else.
     *
     * @return array{
     *   member: OrganisationMember,
     *   projects_added: array<int, array<string, mixed>>,
     *   warnings: array<int, array{project_id:int,code:string,message:string}>,
     * }
     */
    public function accept(WorkspaceInvitation $invitation, User $acceptor): array
    {
        return DB::transaction(function () use ($invitation, $acceptor): array {
            $workspace = Organisation::query()
                ->whereKey($invitation->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSeatAvailable($workspace, $pendingCountsTowardCap = false, code: 'workspace_full', status: 409);

            $member = OrganisationMember::updateOrCreate(
                [
                    'organisation_id' => $workspace->id,
                    'user_id' => $acceptor->id,
                ],
                [
                    'role' => $invitation->role->value,
                    'invited_by' => $invitation->inviter_id,
                    'joined_at' => now(),
                ],
            );

            [$projectsAdded, $warnings] = $this->applyStagedGrants($invitation, $acceptor);

            $invitation->forceFill([
                'status' => WorkspaceInvitationStatus::Accepted->value,
                'accepted_at' => now(),
                'invitee_id' => $acceptor->id,
            ])->save();

            return [
                'member' => $member,
                'projects_added' => $projectsAdded,
                'warnings' => $warnings,
            ];
        });
    }

    public function decline(WorkspaceInvitation $invitation): WorkspaceInvitation
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => ['This invitation has already been '.$invitation->status->value.'.'],
            ]);
        }

        $invitation->forceFill([
            'status' => WorkspaceInvitationStatus::Declined->value,
            'declined_at' => now(),
        ])->save();

        return $invitation;
    }

    public function cancel(WorkspaceInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => ['Only pending invitations can be cancelled.'],
            ]);
        }

        $invitation->delete();
    }

    // ── Create: validation & staging ──────────────────────────────────

    /**
     * Public wrapper around the project-grant validator used by direct
     * provisioning (WorkspaceProvisioningService) so the admin-driven
     * flow runs the same authz + vault-key integrity checks as the
     * invitation flow without duplicating logic.
     *
     * @param  array<int, array<string, mixed>>  $projects
     *
     * @throws ValidationException
     */
    public function validateProjectGrantsFor(Organisation $workspace, User $inviter, array $projects): void
    {
        $this->validateProjectGrants($workspace, $inviter, $projects);
    }

    /**
     * Public seat-cap check — same contract as the private variant used
     * by create()/accept(). Direct provisioning calls this before
     * inserting the user so it fails fast with `code: seat_cap_exceeded`.
     */
    public function assertSeatAvailableFor(
        Organisation $workspace,
        bool $pendingCountsTowardCap,
        string $code = 'seat_cap_exceeded',
        int $status = 422,
    ): void {
        $this->assertSeatAvailable($workspace, $pendingCountsTowardCap, $code, $status);
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     *
     * @throws ValidationException
     */
    private function validateProjectGrants(Organisation $workspace, User $inviter, array $projects): void
    {
        foreach ($projects as $i => $entry) {
            $projectId = (int) $entry['project_id'];

            /** @var Project|null $project */
            $project = Project::query()->whereKey($projectId)->first();

            if ($project === null || (int) $project->organisation_id !== (int) $workspace->id) {
                throw ValidationException::withMessages([
                    "projects.{$i}.project_id" => ['Project does not belong to this workspace.'],
                    'code' => ['invalid_project'],
                ])->status(422);
            }

            $mode = WorkspaceInvitationGrantMode::from((string) $entry['mode']);

            if ($mode === WorkspaceInvitationGrantMode::Project) {
                $this->validateProjectModeGrant($i, $project, $inviter, $entry);
            } else {
                $this->validateResourcesModeGrant($i, $project, $inviter, $entry);
            }
        }
    }

    private function validateProjectModeGrant(int $i, Project $project, User $inviter, array $entry): void
    {
        // Inviter must be a project owner — only owners can grant
        // project-wide access.
        if (! $this->perms->can($inviter, Abilities::SHARE, $project)) {
            throw ValidationException::withMessages([
                "projects.{$i}" => ["You are not a project owner on {$project->name}, so you can't stage project-wide access."],
                'code' => ['cannot_grant_project'],
            ])->status(403);
        }

        $migratedVaultIds = Vault::query()
            ->where('project_id', $project->id)
            ->whereNotNull('migrated_at')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $vaultKeys = (array) ($entry['vault_keys'] ?? []);
        $providedVaultIds = array_values(array_map(
            static fn (array $v): int => (int) $v['vault_id'],
            $vaultKeys,
        ));

        $missing = array_values(array_diff($migratedVaultIds, $providedVaultIds));
        $unknown = array_values(array_diff($providedVaultIds, $migratedVaultIds));

        if ($missing !== []) {
            $names = Vault::query()->whereIn('id', $missing)->pluck('name', 'id')->all();
            throw ValidationException::withMessages([
                "projects.{$i}.vault_keys" => [
                    'Missing wrapped keys for: '.implode(', ', array_map(
                        static fn (int $id): string => $names[$id] ?? "vault #{$id}",
                        $missing,
                    )),
                ],
                'code' => ['missing_vault_keys'],
            ])->status(422);
        }

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                "projects.{$i}.vault_keys" => [
                    'Unknown/non-migrated vaults: '.implode(',', $unknown),
                ],
                'code' => ['unknown_vault'],
            ])->status(422);
        }

        // Per-vault version check + inviter-can-decrypt check.
        foreach ($vaultKeys as $j => $entryKey) {
            $vaultId = (int) $entryKey['vault_id'];
            $wrappedAt = (int) $entryKey['key_version'];

            $currentVersion = (int) ResourceKey::query()
                ->for(ResourceType::Vault, $vaultId)
                ->max('key_version');

            if ($currentVersion > 0 && $wrappedAt !== $currentVersion) {
                throw ValidationException::withMessages([
                    "projects.{$i}.vault_keys.{$j}.key_version" => [
                        "Vault key_version is stale (expected {$currentVersion}).",
                    ],
                    'code' => ['vault_key_rotated'],
                ])->status(422);
            }

            // Inviter-can-decrypt check: they can only wrap for the
            // recipient if they themselves hold a resource_keys row at
            // the current version. Without this, a compromised admin
            // could stage garbage ciphertext the recipient couldn't
            // use.
            $inviterHasKey = ResourceKey::query()
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vaultId)
                ->where('user_id', $inviter->id)
                ->where('key_version', $currentVersion > 0 ? $currentVersion : 1)
                ->exists();

            if (! $inviterHasKey) {
                throw ValidationException::withMessages([
                    "projects.{$i}.vault_keys.{$j}" => [
                        "You don't hold the current key for vault #{$vaultId}.",
                    ],
                    'code' => ['cannot_grant_resource'],
                ])->status(403);
            }
        }
    }

    private function validateResourcesModeGrant(int $i, Project $project, User $inviter, array $entry): void
    {
        $resources = (array) ($entry['resources'] ?? []);

        foreach ($resources as $j => $resource) {
            $type = (string) $resource['type'];
            $id = (int) $resource['id'];

            $resourceModel = match ($type) {
                'vault' => Vault::query()->where('project_id', $project->id)->whereKey($id)->first(),
                'board' => TaskBoard::query()->where('project_id', $project->id)->whereKey($id)->first(),
                'bucket' => ExpenseBucket::query()->where('project_id', $project->id)->whereKey($id)->first(),
                'doc' => \App\Models\Docs\Doc::query()->where('project_id', $project->id)->whereKey($id)->first(),
                default => null,
            };

            if ($resourceModel === null) {
                throw ValidationException::withMessages([
                    "projects.{$i}.resources.{$j}.id" => ["{$type} does not belong to this project."],
                    'code' => ['invalid_resource'],
                ])->status(422);
            }

            if ($type === 'vault') {
                $wrappedAt = (int) $resource['key_version'];
                $currentVersion = (int) ResourceKey::query()
                    ->for(ResourceType::Vault, $id)
                    ->max('key_version');

                if ($currentVersion > 0 && $wrappedAt !== $currentVersion) {
                    throw ValidationException::withMessages([
                        "projects.{$i}.resources.{$j}.key_version" => [
                            "Vault key_version is stale (expected {$currentVersion}).",
                        ],
                        'code' => ['vault_key_rotated'],
                    ])->status(422);
                }

                $inviterHasKey = ResourceKey::query()
                    ->where('resource_type', ResourceType::Vault->value)
                    ->where('resource_id', $id)
                    ->where('user_id', $inviter->id)
                    ->where('key_version', $currentVersion > 0 ? $currentVersion : 1)
                    ->exists();

                if (! $inviterHasKey) {
                    throw ValidationException::withMessages([
                        "projects.{$i}.resources.{$j}" => [
                            "You don't hold the current key for vault #{$id}.",
                        ],
                        'code' => ['cannot_grant_resource'],
                    ])->status(403);
                }

                continue;
            }

            // Board / bucket: inviter must hold Update (editor+) on
            // the resource via the standard permissions pipeline.
            // PermissionService::can resolves cascade + direct grants.
            if (! $this->perms->can($inviter, Abilities::UPDATE, $resourceModel)) {
                throw ValidationException::withMessages([
                    "projects.{$i}.resources.{$j}" => [
                        "You don't have editor access to this {$type} on {$project->name}.",
                    ],
                    'code' => ['cannot_grant_resource'],
                ])->status(403);
            }
        }
    }

    private function stageProjectGrant(WorkspaceInvitation $invitation, array $project): void
    {
        $mode = WorkspaceInvitationGrantMode::from((string) $project['mode']);

        $grant = WorkspaceInvitationProjectGrant::create([
            'invitation_id' => $invitation->id,
            'project_id' => (int) $project['project_id'],
            'mode' => $mode->value,
            'project_role' => $mode === WorkspaceInvitationGrantMode::Project
                ? (string) $project['project_role']
                : null,
        ]);

        if ($mode === WorkspaceInvitationGrantMode::Project) {
            foreach ((array) ($project['vault_keys'] ?? []) as $key) {
                WorkspaceInvitationVaultKey::create([
                    'invitation_project_id' => $grant->id,
                    'vault_id' => (int) $key['vault_id'],
                    'encrypted_key' => (string) $key['encrypted_key'],
                    'key_version' => (int) $key['key_version'],
                ]);
            }

            return;
        }

        foreach ((array) ($project['resources'] ?? []) as $resource) {
            WorkspaceInvitationResourceGrant::create([
                'invitation_project_id' => $grant->id,
                'resource_type' => ResourceType::from(match ($resource['type']) {
                    'vault' => 'vault',
                    'board' => 'board',
                    'bucket' => 'bucket',
                    'doc' => 'doc',
                })->value,
                'resource_id' => (int) $resource['id'],
                'role' => MemberRole::from((string) $resource['role'])->value,
                'encrypted_key' => $resource['encrypted_key'] ?? null,
                'key_version' => isset($resource['key_version']) ? (int) $resource['key_version'] : null,
            ]);
        }
    }

    // ── Accept: apply staged grants ────────────────────────────────────

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{project_id:int,code:string,message:string}>}
     */
    private function applyStagedGrants(WorkspaceInvitation $invitation, User $acceptor): array
    {
        $projectsAdded = [];
        $warnings = [];

        $grants = $invitation->projectGrants()
            ->with(['resourceGrants', 'vaultKeys', 'project'])
            ->get();

        foreach ($grants as $grant) {
            try {
                $applied = $this->applyProjectGrant($grant, $invitation, $acceptor);
                $projectsAdded[] = $applied;
            } catch (StagedGrantWarning $warning) {
                $warnings[] = $warning->toArray();
            }
        }

        return [$projectsAdded, $warnings];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws StagedGrantWarning when the grant can't be applied — the caller
     *         captures it as an inline warning (no transaction rollback).
     */
    private function applyProjectGrant(
        WorkspaceInvitationProjectGrant $grant,
        WorkspaceInvitation $invitation,
        User $acceptor,
    ): array {
        $project = $grant->project;

        if ($project === null || (int) $project->organisation_id !== (int) $invitation->workspace_id) {
            throw new StagedGrantWarning(
                (int) $grant->project_id,
                'project_unavailable',
                'Project no longer exists in this workspace — access not granted. Ask a workspace admin to re-invite.',
            );
        }

        $mode = $grant->mode instanceof WorkspaceInvitationGrantMode
            ? $grant->mode
            : WorkspaceInvitationGrantMode::from((string) $grant->mode);

        return $mode === WorkspaceInvitationGrantMode::Project
            ? $this->applyProjectModeGrant($grant, $project, $invitation, $acceptor)
            : $this->applyResourcesModeGrant($grant, $project, $invitation, $acceptor);
    }

    private function applyProjectModeGrant(
        WorkspaceInvitationProjectGrant $grant,
        Project $project,
        WorkspaceInvitation $invitation,
        User $acceptor,
    ): array {
        /** @var MemberRole $role */
        $role = $grant->project_role;

        // Re-validate vault keys one more time before committing the
        // project grant. A single rotated vault between invite and
        // accept invalidates the whole project grant — the recipient
        // wouldn't be able to decrypt that vault, which violates the
        // "project access means you can see every vault in it"
        // invariant. Drop the whole project grant with a warning so
        // the admin can re-invite fresh.
        foreach ($grant->vaultKeys as $staged) {
            $currentVersion = (int) ResourceKey::query()
                ->for(ResourceType::Vault, (int) $staged->vault_id)
                ->max('key_version');

            $stale = $staged->superseded_at !== null
                || ($currentVersion > 0 && $currentVersion !== (int) $staged->key_version);

            if ($stale) {
                $vaultName = Vault::query()->whereKey($staged->vault_id)->value('name') ?? "#{$staged->vault_id}";
                throw new StagedGrantWarning(
                    (int) $project->id,
                    'vault_rotated',
                    "Vault \"{$vaultName}\" was rotated since this invite was sent — project access not granted. Ask a project owner to re-share.",
                );
            }
        }

        ResourcePermission::updateOrCreate(
            [
                'user_id' => $acceptor->id,
                'resource_type' => ResourceType::Project->value,
                'resource_id' => $project->id,
            ],
            [
                'project_id' => $project->id,
                'role' => $role->value,
                'granted_by' => $invitation->inviter_id,
            ],
        );

        foreach ($grant->vaultKeys as $staged) {
            $currentVersion = (int) ResourceKey::query()
                ->for(ResourceType::Vault, (int) $staged->vault_id)
                ->max('key_version');
            $targetVersion = $currentVersion > 0 ? $currentVersion : 1;

            ResourceKey::updateOrCreate(
                [
                    'resource_type' => ResourceType::Vault->value,
                    'resource_id' => (int) $staged->vault_id,
                    'user_id' => $acceptor->id,
                    'key_version' => $targetVersion,
                ],
                [
                    'project_id' => $project->id,
                    'encrypted_key' => (string) $staged->encrypted_key,
                ],
            );
        }

        return [
            'project_id' => (int) $project->id,
            'mode' => 'project',
            'project_role' => $role->value,
            'vault_keys_applied' => $grant->vaultKeys->count(),
        ];
    }

    private function applyResourcesModeGrant(
        WorkspaceInvitationProjectGrant $grant,
        Project $project,
        WorkspaceInvitation $invitation,
        User $acceptor,
    ): array {
        $resourcesAdded = [];
        $innerWarnings = [];

        foreach ($grant->resourceGrants as $resource) {
            $type = $resource->resource_type instanceof ResourceType
                ? $resource->resource_type
                : ResourceType::from((string) $resource->resource_type);
            $resourceId = (int) $resource->resource_id;

            // Per-resource drop-with-warning: a rotated vault only
            // invalidates the single vault entry, not the whole
            // resources-mode grant. Other resources on the same
            // project entry still land.
            if ($type === ResourceType::Vault) {
                $currentVersion = (int) ResourceKey::query()
                    ->for(ResourceType::Vault, $resourceId)
                    ->max('key_version');

                $stale = $resource->superseded_at !== null
                    || ($currentVersion > 0 && $currentVersion !== (int) $resource->key_version);

                if ($stale) {
                    $vaultName = Vault::query()->whereKey($resourceId)->value('name') ?? "#{$resourceId}";
                    $innerWarnings[] = [
                        'resource_type' => 'vault',
                        'resource_id' => $resourceId,
                        'code' => 'vault_rotated',
                        'message' => "Vault \"{$vaultName}\" was rotated since this invite was sent — access to it not granted.",
                    ];
                    continue;
                }
            }

            ResourcePermission::updateOrCreate(
                [
                    'user_id' => $acceptor->id,
                    'resource_type' => $type->value,
                    'resource_id' => $resourceId,
                ],
                [
                    'project_id' => $project->id,
                    'role' => $resource->role instanceof MemberRole
                        ? $resource->role->value
                        : (string) $resource->role,
                    'granted_by' => $invitation->inviter_id,
                ],
            );

            if ($type === ResourceType::Vault && $resource->encrypted_key !== null) {
                $currentVersion = (int) ResourceKey::query()
                    ->for(ResourceType::Vault, $resourceId)
                    ->max('key_version');
                $targetVersion = $currentVersion > 0 ? $currentVersion : 1;

                ResourceKey::updateOrCreate(
                    [
                        'resource_type' => ResourceType::Vault->value,
                        'resource_id' => $resourceId,
                        'user_id' => $acceptor->id,
                        'key_version' => $targetVersion,
                    ],
                    [
                        'project_id' => $project->id,
                        'encrypted_key' => (string) $resource->encrypted_key,
                    ],
                );
            }

            $resourcesAdded[] = [
                'type' => $type->value,
                'id' => $resourceId,
                'role' => $resource->role instanceof MemberRole
                    ? $resource->role->value
                    : (string) $resource->role,
            ];
        }

        return [
            'project_id' => (int) $project->id,
            'mode' => 'resources',
            'resources_added' => $resourcesAdded,
            'resources_warnings' => $innerWarnings,
        ];
    }

    // ── Seat cap ──────────────────────────────────────────────────────

    private function assertSeatAvailable(
        Organisation $workspace,
        bool $pendingCountsTowardCap,
        string $code = 'seat_cap_exceeded',
        int $status = 422,
    ): void {
        // Self-hosted: the seat_cap column is a cloud-billing artifact
        // (defaults to PlanTier::Free's cap of 1 on freshly-created
        // workspaces) and has no commercial meaning here. The license
        // is the only legitimate seat gate, and LicenseEnforcer
        // (called separately via PlanLimits::assertCanAddMember) is
        // what enforces it. Without this short-circuit, a self-hosted
        // admin gets a misleading "Workspace seat cap of 1 reached"
        // 422 on their very first member invitation.
        if ((string) config('teamcore.edition') === 'self_hosted') {
            return;
        }

        $cap = (int) $workspace->seat_cap;

        $members = OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->count();

        $pending = $pendingCountsTowardCap
            ? WorkspaceInvitation::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', WorkspaceInvitationStatus::Pending->value)
                ->count()
            : 0;

        if ($members + $pending >= $cap) {
            throw ValidationException::withMessages([
                'seat_cap' => ["Workspace seat cap of {$cap} reached."],
                'code' => [$code],
            ])->status($status);
        }
    }
}
