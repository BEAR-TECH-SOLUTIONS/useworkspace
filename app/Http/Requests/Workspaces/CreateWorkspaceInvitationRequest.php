<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Docs\Doc;
use App\Services\Permissions\PermissionService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Consolidated workspace invitation payload.
 *
 * Structural validation only — the service layer checks authz
 * (per-project owner, per-vault decrypt capability) and set-equality
 * against the project's migrated vaults, because those are domain
 * decisions that need DB reads.
 */
class CreateWorkspaceInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller gates via `manageMembers`.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            // Workspace-level role. Project/resource access lives in
            // the `projects` array below.
            'role' => ['required', Rule::enum(OrganisationRole::class)],

            // Optional in the consolidated spec: an empty/missing array
            // means "just join the workspace, scope later" — same
            // behaviour as the bare workspace invite it supersedes.
            'projects' => ['sometimes', 'array'],
            'projects.*.project_id' => ['required_with:projects', 'integer', 'min:1'],
            'projects.*.mode' => ['required_with:projects', Rule::in(['project', 'resources'])],

            // mode='project'
            'projects.*.project_role' => ['required_if:projects.*.mode,project', 'nullable', Rule::enum(MemberRole::class)],
            'projects.*.vault_keys' => ['sometimes', 'array'],
            'projects.*.vault_keys.*.vault_id' => ['required_with:projects.*.vault_keys', 'integer', 'min:1'],
            'projects.*.vault_keys.*.encrypted_key' => ['required_with:projects.*.vault_keys', 'string', 'max:8192'],
            'projects.*.vault_keys.*.key_version' => ['required_with:projects.*.vault_keys', 'integer', 'min:1'],

            // mode='resources'
            'projects.*.resources' => ['required_if:projects.*.mode,resources', 'array', 'min:1'],
            'projects.*.resources.*.type' => ['required_with:projects.*.resources', Rule::in(['vault', 'board', 'bucket', 'doc'])],
            'projects.*.resources.*.id' => ['required_with:projects.*.resources', 'integer', 'min:1'],
            'projects.*.resources.*.role' => ['required_with:projects.*.resources', Rule::enum(MemberRole::class)],
            'projects.*.resources.*.encrypted_key' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'projects.*.resources.*.key_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $this->assertAdminRoleRestrictedToAdminCallers($v);
            $this->assertGrantsDontExceedInviterRole($v);

            $projects = (array) $this->input('projects', []);

            foreach ($projects as $i => $row) {
                $mode = $row['mode'] ?? null;
                if ($mode !== 'resources') {
                    continue;
                }

                // Vault-type resources need both encrypted_key AND
                // key_version; wrapping without either means the
                // accept handler can't materialise `resource_keys`.
                foreach ((array) ($row['resources'] ?? []) as $j => $resource) {
                    if (($resource['type'] ?? null) !== 'vault') {
                        continue;
                    }

                    if (empty($resource['encrypted_key'])) {
                        $v->errors()->add(
                            "projects.{$i}.resources.{$j}.encrypted_key",
                            'encrypted_key is required for vault grants.',
                        );
                    }
                    if (empty($resource['key_version'])) {
                        $v->errors()->add(
                            "projects.{$i}.resources.{$j}.key_version",
                            'key_version is required for vault grants.',
                        );
                    }
                }
            }
        });
    }

    /**
     * Audit H2: a non-admin caller (member with the
     * `members_can_invite_members` toggle on) must not be able to
     * issue an Admin-role invitation. The controller's
     * `inviteMembers` gate lets them through; this rule narrows the
     * permissible roles based on the caller's standing.
     */
    private function assertAdminRoleRestrictedToAdminCallers(Validator $v): void
    {
        $role = (string) $this->input('role', '');
        if ($role !== OrganisationRole::Admin->value) {
            return;
        }

        $workspace = $this->route('workspace');
        $user = $this->user();
        if (! $workspace instanceof Organisation || $user === null) {
            return;
        }

        $isAdmin = (int) $workspace->owner_id === (int) $user->id
            || OrganisationMember::query()
                ->where('organisation_id', $workspace->id)
                ->where('user_id', $user->id)
                ->where('role', OrganisationRole::Admin->value)
                ->exists();

        if (! $isAdmin) {
            $v->errors()->add(
                'role',
                'Only workspace admins can issue admin invitations.',
            );
        }
    }

    /**
     * Audit H1: per-resource grants must not exceed the inviter's own
     * effective role on the resource. An Editor cannot grant Owner;
     * a Viewer cannot grant Editor. Without this, a compromised
     * Editor account can promote a colluding account to Owner of a
     * board the Editor doesn't own.
     */
    private function assertGrantsDontExceedInviterRole(Validator $v): void
    {
        $projects = (array) $this->input('projects', []);
        if ($projects === []) {
            return;
        }

        $inviter = $this->user();
        if ($inviter === null) {
            return;
        }

        /** @var PermissionService $perms */
        $perms = app(PermissionService::class);

        foreach ($projects as $i => $row) {
            $projectId = (int) ($row['project_id'] ?? 0);
            $project = $projectId > 0 ? Project::query()->find($projectId) : null;
            if ($project === null) {
                continue; // exists rule will catch
            }

            $mode = $row['mode'] ?? null;

            if ($mode === 'project') {
                $payloadRole = $row['project_role'] ?? null;
                if ($payloadRole === null) {
                    continue;
                }
                $inviterRole = $perms->effectiveRole($inviter, $project);
                if (! self::canGrant($inviterRole, MemberRole::tryFrom((string) $payloadRole))) {
                    $v->errors()->add(
                        "projects.{$i}.project_role",
                        'You cannot grant a role higher than your own on this project.',
                    );
                }
                continue;
            }

            if ($mode !== 'resources') {
                continue;
            }

            foreach ((array) ($row['resources'] ?? []) as $j => $resource) {
                $type = $resource['type'] ?? null;
                $resourceId = (int) ($resource['id'] ?? 0);
                $payloadRole = MemberRole::tryFrom((string) ($resource['role'] ?? ''));
                if ($type === null || $resourceId <= 0 || $payloadRole === null) {
                    continue;
                }

                $model = match ($type) {
                    'vault' => Vault::query()->find($resourceId),
                    'board' => TaskBoard::query()->find($resourceId),
                    'bucket' => ExpenseBucket::query()->find($resourceId),
                    'doc' => Doc::query()->find($resourceId),
                    default => null,
                };
                if ($model === null) {
                    continue;
                }

                $inviterRole = $perms->effectiveRole($inviter, $model);
                if (! self::canGrant($inviterRole, $payloadRole)) {
                    $v->errors()->add(
                        "projects.{$i}.resources.{$j}.role",
                        'You cannot grant a role higher than your own on this resource.',
                    );
                }
            }
        }
    }

    /**
     * Role hierarchy: Owner > Editor > Viewer. An inviter can grant
     * any role at or below their own effective role on the resource.
     */
    private static function canGrant(?MemberRole $inviter, ?MemberRole $payload): bool
    {
        if ($inviter === null || $payload === null) {
            return false;
        }
        $rank = static fn (MemberRole $r): int => match ($r) {
            MemberRole::Owner => 3,
            MemberRole::Editor => 2,
            MemberRole::Viewer => 1,
        };

        return $rank($payload) <= $rank($inviter);
    }
}
