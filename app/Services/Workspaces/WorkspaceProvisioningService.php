<?php

namespace App\Services\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Enums\PlanTier;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\DeferredAccessGrant;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Identity\PersonalProjectFactory;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Admin-driven direct user provisioning for Business / Self-Hosted
 * workspaces.
 *
 * Split-provisioning model: non-vault access is applied IMMEDIATELY
 * (project-level role rows, board rows, bucket rows) so the user lands
 * in a fully-populated workspace on first login. Only vault key
 * wrapping — which requires the user's yet-to-exist public key — is
 * deferred via `deferred_access_grants` rows for an owner to finalise
 * once master-password setup completes.
 *
 * Provisioned users also get their own personal workspace + default
 * project, same as `POST /register`, so their onboarding matches the
 * self-signup path exactly.
 */
class WorkspaceProvisioningService
{
    public function __construct(
        private readonly WorkspaceInvitationService $invitations,
        private readonly PermissionService $perms,
        private readonly PersonalProjectFactory $personalProjects,
    ) {}

    public function isAvailableFor(Organisation $workspace): bool
    {
        $tier = $workspace->tier;

        // Eloquent normally returns a PlanTier via the model cast.
        // If a legacy / unknown value somehow lives in the column
        // (e.g. a DB row predating the WorkspaceTier→PlanTier rename
        // whose consolidation migration hasn't run), fall through
        // to a safe `false` rather than throwing — the
        // controller's feature_not_available response surfaces
        // the right error code to the client without taking down
        // the request.
        if (! $tier instanceof PlanTier) {
            $tier = PlanTier::tryFrom((string) $tier);
        }

        return $tier !== null && $tier->supportsDirectProvisioning();
    }

    /**
     * @param  array<int, array<string, mixed>>  $projects
     *
     * @return array{
     *   user: User,
     *   membership: OrganisationMember,
     *   projects_added: array<int, array<string, mixed>>,
     *   deferred_vault_grants: array<int, array{project_id:int, vault_count:int}>,
     * }
     *
     * @throws ValidationException
     */
    public function provision(
        Organisation $workspace,
        User $admin,
        string $email,
        string $name,
        string $password,
        OrganisationRole $role,
        array $projects,
        bool $createPersonalWorkspace = true,
    ): array {
        $normalisedEmail = strtolower(trim($email));

        if (User::query()->whereRaw('lower(email) = ?', [$normalisedEmail])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['A user with this email already exists.'],
                'code' => ['email_taken'],
            ])->status(422);
        }

        // Admin-holds-rights check runs before any writes so unqualified
        // grants fail fast without leaving half-created user state.
        $this->validateProjectGrants($workspace, $admin, $projects);

        return DB::transaction(function () use ($workspace, $admin, $email, $name, $password, $role, $projects, $createPersonalWorkspace): array {
            $this->invitations->assertSeatAvailableFor($workspace, pendingCountsTowardCap: false);

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password_hash' => Hash::make($password),
                'email_verified_at' => Carbon::now(),
            ]);

            // Personal workspace mirrors POST /register so provisioned
            // users land in the same onboarding shape as self-signup.
            // Optional-flag guarded so enterprise deployments can skip
            // the default if they want a stripped-down tenant.
            if ($createPersonalWorkspace) {
                $this->personalProjects->bootstrap($user);
            }

            $membership = OrganisationMember::create([
                'organisation_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => $role->value,
                'invited_by' => $admin->id,
                'joined_at' => Carbon::now(),
            ]);

            $projectsAdded = [];
            $deferredVaultGrants = [];

            foreach ($projects as $entry) {
                [$applied, $deferred] = $this->materialiseProjectEntry($workspace, $admin, $user, (array) $entry);

                $projectsAdded[] = $applied;
                if ($deferred !== null) {
                    $deferredVaultGrants[] = $deferred;
                }
            }

            return [
                'user' => $user,
                'membership' => $membership,
                'projects_added' => $projectsAdded,
                'deferred_vault_grants' => $deferredVaultGrants,
            ];
        });
    }

    /**
     * Apply one project entry. Returns:
     *  - $applied: summary matching the pre-split `projects_added` shape
     *  - $deferred: {project_id, vault_count} when any vaults needed
     *               keys wrapped, null otherwise
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: ?array{project_id:int, vault_count:int}}
     */
    private function materialiseProjectEntry(Organisation $workspace, User $admin, User $user, array $entry): array
    {
        $projectId = (int) $entry['project_id'];
        $mode = (string) $entry['mode'];

        if ($mode === 'project') {
            return $this->applyProjectMode($workspace, $admin, $user, $projectId, $entry);
        }

        return $this->applyResourcesMode($workspace, $admin, $user, $projectId, $entry);
    }

    /**
     * mode=project: create the project-level resource_permissions row
     * immediately (it cascades to boards/buckets/vaults for visibility).
     * Defer only the resource_keys rows the user needs to actually
     * decrypt migrated vault contents.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: ?array{project_id:int, vault_count:int}}
     */
    private function applyProjectMode(Organisation $workspace, User $admin, User $user, int $projectId, array $entry): array
    {
        $projectRole = MemberRole::from((string) $entry['project_role']);

        ResourcePermission::updateOrCreate(
            [
                'user_id' => $user->id,
                'resource_type' => ResourceType::Project->value,
                'resource_id' => $projectId,
            ],
            [
                'project_id' => $projectId,
                'role' => $projectRole->value,
                'granted_by' => $admin->id,
            ],
        );

        $migratedVaults = Vault::query()
            ->where('project_id', $projectId)
            ->whereNotNull('migrated_at')
            ->get(['id', 'name']);

        $deferred = null;

        if ($migratedVaults->isNotEmpty()) {
            DeferredAccessGrant::updateOrCreate(
                ['user_id' => $user->id, 'project_id' => $projectId],
                [
                    'workspace_id' => $workspace->id,
                    'provisioned_by' => $admin->id,
                    'mode' => 'project',
                    'project_role' => $projectRole->value,
                    'resources' => $migratedVaults->map(static fn (Vault $v): array => [
                        'vault_id' => (int) $v->id,
                    ])->values()->all(),
                    'created_at' => Carbon::now(),
                ],
            );

            $deferred = [
                'project_id' => $projectId,
                'vault_count' => $migratedVaults->count(),
            ];
        }

        return [
            [
                'project_id' => $projectId,
                'mode' => 'project',
                'project_role' => $projectRole->value,
            ],
            $deferred,
        ];
    }

    /**
     * mode=resources: apply board/bucket rows immediately; stash one
     * deferred row per project carrying the vaults that still need
     * keys wrapped. The user sees every board and bucket they were
     * granted on first login; vault access lights up once an owner
     * runs finalise.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: array<string, mixed>, 1: ?array{project_id:int, vault_count:int}}
     */
    private function applyResourcesMode(Organisation $workspace, User $admin, User $user, int $projectId, array $entry): array
    {
        $resources = (array) ($entry['resources'] ?? []);
        $vaultEntries = [];
        $appliedCount = 0;

        foreach ($resources as $resource) {
            $type = (string) $resource['type'];
            $resourceId = (int) $resource['id'];
            $role = MemberRole::from((string) $resource['role']);

            if ($type === 'vault') {
                $vaultEntries[] = [
                    'vault_id' => $resourceId,
                    'role' => $role->value,
                ];
                continue;
            }

            // board / bucket / doc — no crypto, apply straight away.
            $resourceTypeEnum = match ($type) {
                'board' => ResourceType::Board,
                'bucket' => ResourceType::Bucket,
                'doc' => ResourceType::Doc,
            };

            ResourcePermission::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'resource_type' => $resourceTypeEnum->value,
                    'resource_id' => $resourceId,
                ],
                [
                    'project_id' => $projectId,
                    'role' => $role->value,
                    'granted_by' => $admin->id,
                ],
            );

            $appliedCount++;
        }

        $deferred = null;
        if ($vaultEntries !== []) {
            DeferredAccessGrant::updateOrCreate(
                ['user_id' => $user->id, 'project_id' => $projectId],
                [
                    'workspace_id' => $workspace->id,
                    'provisioned_by' => $admin->id,
                    'mode' => 'resources',
                    'project_role' => null,
                    'resources' => $vaultEntries,
                    'created_at' => Carbon::now(),
                ],
            );

            $deferred = [
                'project_id' => $projectId,
                'vault_count' => count($vaultEntries),
            ];
        }

        return [
            [
                'project_id' => $projectId,
                'mode' => 'resources',
                'resources_count' => $appliedCount + count($vaultEntries),
            ],
            $deferred,
        ];
    }

    /**
     * Admin-holds-rights validator for the provisioning flow. Vault
     * key versions are NOT checked here — the admin isn't wrapping
     * keys, the owner-driven finalise step is. Only authz + structural
     * checks run here.
     *
     * @param  array<int, array<string, mixed>>  $projects
     */
    private function validateProjectGrants(Organisation $workspace, User $admin, array $projects): void
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

            $mode = (string) $entry['mode'];

            if ($mode === 'project') {
                if (! $this->perms->can($admin, Abilities::SHARE, $project)) {
                    throw ValidationException::withMessages([
                        "projects.{$i}" => ["You are not a project owner on {$project->name}, so you can't grant project-wide access."],
                        'code' => ['cannot_grant_project'],
                    ])->status(403);
                }

                continue;
            }

            foreach ((array) ($entry['resources'] ?? []) as $j => $resource) {
                $type = (string) $resource['type'];
                $resourceId = (int) $resource['id'];

                $resourceModel = match ($type) {
                    'vault' => Vault::query()->where('project_id', $project->id)->whereKey($resourceId)->first(),
                    'board' => TaskBoard::query()->where('project_id', $project->id)->whereKey($resourceId)->first(),
                    'bucket' => ExpenseBucket::query()->where('project_id', $project->id)->whereKey($resourceId)->first(),
                    'doc' => \App\Models\Docs\Doc::query()->where('project_id', $project->id)->whereKey($resourceId)->first(),
                    default => null,
                };

                if ($resourceModel === null) {
                    throw ValidationException::withMessages([
                        "projects.{$i}.resources.{$j}.id" => ["{$type} does not belong to this project."],
                        'code' => ['invalid_resource'],
                    ])->status(422);
                }

                if (! $this->perms->can($admin, Abilities::UPDATE, $resourceModel)) {
                    throw ValidationException::withMessages([
                        "projects.{$i}.resources.{$j}" => [
                            "You don't have editor access to this {$type} on {$project->name}.",
                        ],
                        'code' => ['cannot_grant_resource'],
                    ])->status(403);
                }
            }
        }
    }
}
