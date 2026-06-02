<?php

namespace App\Services\Permissions;

use App\Enums\AuditAction;
use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Single source of truth for the polymorphic ACL described in CLAUDE.md §5.
 *
 * Resolution rule (most specific wins):
 *   1. A direct grant on the exact resource.
 *   2. A grant on the parent project (cascade).
 *   3. None → null.
 *
 * The owner of a project (projects.owner_id) is implicitly an Owner on every
 * resource inside that project, regardless of resource_permissions rows.
 */
class PermissionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Resolve the effective role of $user on $resource.
     */
    public function effectiveRole(User $user, Model $resource): ?MemberRole
    {
        // Credentials are not directly grantable; resolve via parent vault
        // (or the project, when the credential lives in "All entries").
        if ($resource instanceof Credential) {
            $resource = $resource->vault_id !== null
                ? (Vault::find($resource->vault_id) ?? Project::find($resource->project_id))
                : Project::find($resource->project_id);

            if ($resource === null) {
                return null;
            }
        }

        // Expenses are not directly grantable either; resolve via their parent bucket.
        if ($resource instanceof Expense) {
            $resource = ExpenseBucket::find($resource->bucket_id)
                ?? Project::find($resource->project_id);

            if ($resource === null) {
                return null;
            }
        }

        $project = $this->projectOf($resource);

        if ($project === null) {
            return null;
        }

        if ($project->owner_id === $user->id) {
            return MemberRole::Owner;
        }

        $resourceType = $this->resourceTypeOf($resource);

        // 1. Direct grant on the exact resource (skipped when the resource IS the project,
        //    since that case is identical to step 2).
        if ($resourceType !== ResourceType::Project) {
            $direct = ResourcePermission::query()
                ->where('user_id', $user->id)
                ->where('resource_type', $resourceType->value)
                ->where('resource_id', $resource->getKey())
                ->value('role');

            if ($direct !== null) {
                return $direct instanceof MemberRole ? $direct : MemberRole::from($direct);
            }
        }

        // 2. Grant on the parent project.
        $projectGrant = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->value('role');

        if ($projectGrant !== null) {
            return $projectGrant instanceof MemberRole ? $projectGrant : MemberRole::from($projectGrant);
        }

        return null;
    }

    public function can(User $user, string $ability, Model $resource): bool
    {
        $role = $this->effectiveRole($user, $resource);

        if ($role === null) {
            return false;
        }

        return Abilities::allows($role, $ability);
    }

    public function authorize(User $user, string $ability, Model $resource): void
    {
        if (! $this->can($user, $ability, $resource)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * Returns a query for every resource of $type the user can read inside $project.
     * Used to power sidebar / list endpoints.
     */
    public function visibleScope(User $user, ResourceType $type, Project $project): Builder
    {
        $query = $this->baseQueryFor($type)->where('project_id', $project->id);

        if ($project->owner_id === $user->id) {
            return $query;
        }

        $hasProjectGrant = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->exists();

        if ($hasProjectGrant) {
            return $query;
        }

        // Otherwise, only resources with a direct grant.
        return $query->whereIn('id', function ($sub) use ($user, $type, $project): void {
            $sub->select('resource_id')
                ->from('resource_permissions')
                ->where('user_id', $user->id)
                ->where('resource_type', $type->value)
                ->where('project_id', $project->id);
        });
    }

    public function grant(User $granter, User $target, Model $resource, MemberRole $role, ?string $encryptedKey = null): ResourcePermission
    {
        $project = $this->projectOf($resource);

        if ($project === null) {
            throw new InvalidArgumentException('Cannot grant on a resource without a project context.');
        }

        $type = $this->resourceTypeOf($resource);

        $this->assertEncryptedKeyShape($type, $encryptedKey);

        return DB::transaction(function () use ($granter, $target, $resource, $role, $project, $type, $encryptedKey): ResourcePermission {
            $previousRole = ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('resource_type', $type->value)
                ->where('resource_id', $resource->getKey())
                ->value('role');

            $permission = ResourcePermission::updateOrCreate(
                [
                    'user_id' => $target->id,
                    'resource_type' => $type->value,
                    'resource_id' => $resource->getKey(),
                ],
                [
                    'project_id' => $project->id,
                    'role' => $role->value,
                    'granted_by' => $granter->id,
                ],
            );

            // Crypto plane: vault and project grants optionally carry a
            // wrapped key. The DB CHECK constraint on resource_keys pins
            // inserts to these two types, so any future "extend to boards"
            // regression trips at the database layer, not here.
            if ($encryptedKey !== null) {
                // For vaults, the wrapped key must match the vault's
                // CURRENT key_version (shared across all members) so the
                // payload the client just wrapped can actually decrypt
                // the current ciphertext. If no rows exist yet (pre-
                // migrate / project-level), start at version 1.
                $currentVersion = (int) ResourceKey::query()
                    ->for($type, $resource->getKey())
                    ->max('key_version');

                $targetVersion = $currentVersion > 0 ? $currentVersion : 1;

                ResourceKey::updateOrCreate(
                    [
                        'resource_type' => $type->value,
                        'resource_id' => $resource->getKey(),
                        'user_id' => $target->id,
                        'key_version' => $targetVersion,
                    ],
                    [
                        'project_id' => $project->id,
                        'encrypted_key' => $encryptedKey,
                    ],
                );
            }

            $previousValue = $previousRole instanceof MemberRole ? $previousRole->value : $previousRole;

            $this->audit->record(
                actor: $granter,
                action: AuditAction::ResourceGranted,
                projectId: $project->id,
                resourceType: $type,
                resourceId: $resource->getKey(),
                targetUserId: $target->id,
                metadata: [
                    'role' => $role->value,
                    'previous_role' => $previousValue,
                    'with_key' => $encryptedKey !== null,
                ],
            );

            return $permission;
        });
    }

    /**
     * Would removing $target's direct grant on $resource leave the
     * resource with at least one effective Owner? Used by per-resource
     * member destroy endpoints to enforce the "cannot remove last owner"
     * invariant before calling revoke().
     *
     * Effective owners = {project.owner_id} ∪ {users with a project-level
     * Owner grant} ∪ {users with a direct Owner grant on $resource}. The
     * target's direct row on $resource is excluded from the last set
     * because that's the row we're about to delete.
     */
    /**
     * Does $user have ANY grant (project-level or child-level) inside
     * $project? Used to gate list endpoints that need to be reachable by
     * Pattern B users — a user with only a direct vault/board/bucket
     * grant cannot pass `view` on the project itself, but must still be
     * able to call the list endpoints so visibleScope() can return the
     * narrow slice they're allowed to see.
     */
    public function hasAnyGrantIn(User $user, Project $project): bool
    {
        if ($project->owner_id === $user->id) {
            return true;
        }

        return ResourcePermission::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->exists();
    }

    public function wouldLeaveResourceOwnerless(User $target, Model $resource): bool
    {
        $project = $this->projectOf($resource);

        if ($project === null) {
            return false;
        }

        $type = $this->resourceTypeOf($resource);

        $owners = [$project->owner_id];

        $projectOwnerRows = ResourcePermission::query()
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->where('role', MemberRole::Owner->value)
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        foreach ($projectOwnerRows as $id) {
            $owners[] = $id;
        }

        if ($type !== ResourceType::Project) {
            $directOwnerRows = ResourcePermission::query()
                ->where('resource_type', $type->value)
                ->where('resource_id', $resource->getKey())
                ->where('role', MemberRole::Owner->value)
                ->where('user_id', '!=', $target->id)
                ->pluck('user_id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            foreach ($directOwnerRows as $id) {
                $owners[] = $id;
            }
        }

        return array_values(array_unique($owners)) === [];
    }

    public function revoke(User $target, Model $resource, ?User $revoker = null): void
    {
        $type = $this->resourceTypeOf($resource);
        $project = $this->projectOf($resource);

        DB::transaction(function () use ($target, $resource, $type, $project, $revoker): void {
            $permission = ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('resource_type', $type->value)
                ->where('resource_id', $resource->getKey())
                ->first();

            if ($permission === null) {
                return;
            }

            $permission->delete();

            // Crypto plane: drop any wrapped keys for this (user, resource)
            // pair so an old cached key can't be used to decrypt post-revoke.
            // Only project/vault rows exist in resource_keys by DB CHECK.
            ResourceKey::query()
                ->where('user_id', $target->id)
                ->where('resource_type', $type->value)
                ->where('resource_id', $resource->getKey())
                ->delete();

            $this->audit->record(
                actor: $revoker,
                action: AuditAction::ResourceRevoked,
                projectId: $project?->id,
                resourceType: $type,
                resourceId: $resource->getKey(),
                targetUserId: $target->id,
                metadata: [
                    'previous_role' => $permission->role instanceof MemberRole
                        ? $permission->role->value
                        : $permission->role,
                ],
            );
        });
    }

    /**
     * Single-transaction replacement of a user's access to a project.
     * Backs `PUT /projects/{project}/members/{user}/access`. Replaces the
     * legacy POST/DELETE/PATCH multi-call dance across three routes.
     *
     * - mode="project"   → upsert a project-level grant at $projectRole
     *                      and drop every child (vault/board/bucket) row
     *                      for (user, project), plus their wrapped keys.
     * - mode="resources" → drop the project-level row, sync the child
     *                      rows to exactly $resources, and upsert
     *                      resource_keys for every vault entry at the
     *                      vault's current key_version. Wrapped keys for
     *                      vaults no longer in $resources are deleted.
     * - mode="none"      → delete everything (delegates to revokeRecursive).
     *
     * @param  array<int, array{type:string,id:int,role:string,encrypted_key?:?string}>  $resources
     */
    public function setMemberAccess(
        User $granter,
        User $target,
        Project $project,
        string $mode,
        ?MemberRole $projectRole,
        array $resources,
    ): void {
        DB::transaction(function () use ($granter, $target, $project, $mode, $projectRole, $resources): void {
            match ($mode) {
                'project' => $this->applyModeProject($granter, $target, $project, $projectRole),
                'resources' => $this->applyModeResources($granter, $target, $project, $resources),
                'none' => $this->revokeRecursive($target, $project, $granter),
                default => throw new InvalidArgumentException("Unknown access mode: {$mode}"),
            };

            // Deferred-provisioning cleanup: a manual access mutation
            // supersedes any deferred intent captured at provisioning
            // time. Drop the stale row so the admin inbox doesn't
            // still show this (user, project) as "awaiting vault
            // keys". Survives across all three modes including 'none'
            // (the user lost access, so the deferred row is moot).
            DB::table('deferred_access_grants')
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->delete();
        });
    }

    private function applyModeProject(User $granter, User $target, Project $project, ?MemberRole $projectRole): void
    {
        if ($projectRole === null) {
            throw new InvalidArgumentException('project_role is required for mode=project');
        }

        // Drop every direct child grant (vault/board/bucket) — the
        // project-level row upserted below makes them redundant. Before
        // deleting we capture the vault_ids whose Pattern B row we're
        // about to remove; those are the ONLY vaults whose wrapped keys
        // become orphan noise. Wrapped keys the user already holds via
        // project-level wrap (invitation vault_keys, wrap-key, initial
        // migration) must stay — deleting them would lock the user out
        // of every credential while leaving them a nominal project
        // member. Concrete repro: role change viewer → owner via
        // `mode:"project"` used to blow away every resource_keys row
        // and render every vault un-decryptable.
        $childRows = ResourcePermission::query()
            ->where('user_id', $target->id)
            ->where('project_id', $project->id)
            ->whereIn('resource_type', [
                ResourceType::Vault->value,
                ResourceType::Board->value,
                ResourceType::Bucket->value,
                ResourceType::Doc->value,
            ])
            ->get();

        $vaultIdsWithDeletedGrant = [];

        foreach ($childRows as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type
                : ResourceType::from($row->resource_type);

            if ($type === ResourceType::Vault) {
                $vaultIdsWithDeletedGrant[] = (int) $row->resource_id;
            }

            $row->delete();

            $this->audit->record(
                actor: $granter,
                action: AuditAction::ResourceRevoked,
                projectId: $project->id,
                resourceType: $type,
                resourceId: (int) $row->resource_id,
                targetUserId: $target->id,
                metadata: [
                    'previous_role' => $row->role instanceof MemberRole
                        ? $row->role->value
                        : $row->role,
                    'reason' => 'mode_project_cascade',
                ],
            );
        }

        // Only touch resource_keys that were paired with a now-deleted
        // Pattern B vault grant. Other rows stay untouched so project
        // members keep decryptability across role changes.
        if ($vaultIdsWithDeletedGrant !== []) {
            ResourceKey::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->where('resource_type', ResourceType::Vault->value)
                ->whereIn('resource_id', $vaultIdsWithDeletedGrant)
                ->delete();
        }

        $this->grant($granter, $target, $project, $projectRole);
    }

    /**
     * @param  array<int, array{type:string,id:int,role:string,encrypted_key?:?string}>  $resources
     */
    private function applyModeResources(User $granter, User $target, Project $project, array $resources): void
    {
        // Project-level row (and its wrapped key, if any) goes first — the
        // user is downgrading from cascading access to resource-only.
        $projectRow = ResourcePermission::query()
            ->where('user_id', $target->id)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->first();

        if ($projectRow !== null) {
            $projectRow->delete();

            ResourceKey::query()
                ->where('user_id', $target->id)
                ->where('resource_type', ResourceType::Project->value)
                ->where('resource_id', $project->id)
                ->delete();

            $this->audit->record(
                actor: $granter,
                action: AuditAction::ResourceRevoked,
                projectId: $project->id,
                resourceType: ResourceType::Project,
                resourceId: $project->id,
                targetUserId: $target->id,
                metadata: [
                    'previous_role' => $projectRow->role instanceof MemberRole
                        ? $projectRow->role->value
                        : $projectRow->role,
                    'reason' => 'mode_resources_demote',
                ],
            );
        }

        // Normalise the payload by resource type; the tuple-set we want
        // live on the project after the call is exactly this.
        $desired = []; // key "type:id" → ['type','id','role','encrypted_key']
        foreach ($resources as $r) {
            $desired["{$r['type']}:{$r['id']}"] = $r;
        }

        // Delete any existing (vault/board/bucket) grants not in $desired.
        $existing = ResourcePermission::query()
            ->where('user_id', $target->id)
            ->where('project_id', $project->id)
            ->whereIn('resource_type', [
                ResourceType::Vault->value,
                ResourceType::Board->value,
                ResourceType::Bucket->value,
                ResourceType::Doc->value,
            ])
            ->get();

        foreach ($existing as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type->value
                : (string) $row->resource_type;
            $key = "{$type}:{$row->resource_id}";

            if (isset($desired[$key])) {
                continue;
            }

            $row->delete();

            // Vault rows also carry wrapped keys — drop any for this
            // (user, vault) pair so a stale cached key can't decrypt
            // post-revoke.
            if ($type === ResourceType::Vault->value) {
                ResourceKey::query()
                    ->where('user_id', $target->id)
                    ->where('resource_type', ResourceType::Vault->value)
                    ->where('resource_id', $row->resource_id)
                    ->delete();
            }

            $this->audit->record(
                actor: $granter,
                action: AuditAction::ResourceRevoked,
                projectId: $project->id,
                resourceType: ResourceType::from($type),
                resourceId: (int) $row->resource_id,
                targetUserId: $target->id,
                metadata: [
                    'previous_role' => $row->role instanceof MemberRole
                        ? $row->role->value
                        : $row->role,
                    'reason' => 'mode_resources_sync',
                ],
            );
        }

        // Upsert everything in $desired. grant() handles both insert and
        // update, writes the audit row, and — for vaults — stamps the
        // wrapped key at the vault's current key_version.
        foreach ($desired as $r) {
            $role = MemberRole::from($r['role']);
            $resource = $this->loadResourceForGrant($project, $r['type'], (int) $r['id']);
            $encryptedKey = $r['type'] === 'vault' ? ($r['encrypted_key'] ?? null) : null;

            $this->grant($granter, $target, $resource, $role, encryptedKey: $encryptedKey);
        }
    }

    private function loadResourceForGrant(Project $project, string $type, int $id): Model
    {
        $resource = match ($type) {
            'vault' => Vault::query()->where('project_id', $project->id)->whereKey($id)->first(),
            'board' => TaskBoard::query()->where('project_id', $project->id)->whereKey($id)->first(),
            'bucket' => ExpenseBucket::query()->where('project_id', $project->id)->whereKey($id)->first(),
            'doc' => Doc::query()->where('project_id', $project->id)->whereKey($id)->first(),
            default => throw new InvalidArgumentException("Unsupported resource type: {$type}"),
        };

        if ($resource === null) {
            throw new InvalidArgumentException("{$type} id {$id} does not belong to project {$project->id}");
        }

        return $resource;
    }

    /**
     * Revoke only the project-level grant (resource_type='project') and its
     * matching resource_keys row, leaving any direct vault/board/bucket
     * grants intact. Used by DELETE /projects/{p}/members/{u} when the
     * caller passes `?keep_resource_grants=true` — it's how the client
     * demotes a project member to a resource-only grantee without the
     * cascading delete dropping everything they still need.
     */
    public function revokeProjectLevelOnly(User $target, Project $project, ?User $revoker = null): void
    {
        DB::transaction(function () use ($target, $project, $revoker): void {
            $row = ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->where('resource_type', ResourceType::Project->value)
                ->where('resource_id', $project->id)
                ->first();

            if ($row === null) {
                return;
            }

            $row->delete();

            ResourceKey::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->where('resource_type', ResourceType::Project->value)
                ->where('resource_id', $project->id)
                ->delete();

            $this->audit->record(
                actor: $revoker,
                action: AuditAction::ResourceRevoked,
                projectId: $project->id,
                resourceType: ResourceType::Project,
                resourceId: $project->id,
                targetUserId: $target->id,
                metadata: [
                    'previous_role' => $row->role instanceof MemberRole
                        ? $row->role->value
                        : $row->role,
                    'keep_resource_grants' => true,
                ],
            );
        });
    }

    /**
     * Wipe every grant the user has anywhere inside the project (project-level + child resources).
     */
    public function revokeRecursive(User $target, Project $project, ?User $revoker = null): void
    {
        DB::transaction(function () use ($target, $project, $revoker): void {
            $rows = ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->get();

            if ($rows->isEmpty()) {
                return;
            }

            ResourcePermission::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->delete();

            ResourceKey::query()
                ->where('user_id', $target->id)
                ->where('project_id', $project->id)
                ->delete();

            foreach ($rows as $row) {
                $this->audit->record(
                    actor: $revoker,
                    action: AuditAction::ResourceRevoked,
                    projectId: $project->id,
                    resourceType: $row->resource_type instanceof ResourceType
                        ? $row->resource_type
                        : ResourceType::from($row->resource_type),
                    resourceId: (int) $row->resource_id,
                    targetUserId: $target->id,
                    metadata: [
                        'previous_role' => $row->role instanceof MemberRole
                            ? $row->role->value
                            : $row->role,
                        'cascade' => true,
                    ],
                );
            }
        });
    }

    /**
     * Enforce the vault grant completeness invariant.
     *
     * A vault must always have a wrapped key for every project owner (plus
     * any `$extraRecipient` — used when the invariant runs *before* the new
     * owner has been promoted, e.g. inside an invitation transaction).
     *
     * This is the load-bearing correctness check for the encryption model:
     * if the server doesn't enforce it, a malicious or buggy client can
     * create a vault that no owner can decrypt. Set-equality (not
     * count-equality) is mandatory — a client sending the right *count* of
     * grants pointed at the wrong user IDs would otherwise slip through.
     *
     * Runs inside a REPEATABLE READ transaction so the "current owners"
     * snapshot can't drift between the check and the subsequent writes
     * the caller performs. Callers that already hold a transaction will
     * reuse it; otherwise this method opens one.
     *
     * @param  array<int, array{user_id: int}>  $grants
     * @throws ValidationException with 422 details on mismatch.
     */
    public function enforceVaultGrantCompleteness(int $projectId, array $grants, ?int $extraRecipient = null): void
    {
        $run = function () use ($projectId, $grants, $extraRecipient): void {
            // Postgres REPEATABLE READ is scoped to the transaction; raise
            // the isolation level for this check so two concurrent invites
            // cannot both pass a completeness check against a stale owner
            // snapshot.
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            $expected = ResourcePermission::query()
                ->where('resource_type', ResourceType::Project->value)
                ->where('resource_id', $projectId)
                ->where('role', MemberRole::Owner->value)
                ->pluck('user_id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            if ($extraRecipient !== null) {
                $expected[] = $extraRecipient;
            }

            $expected = array_values(array_unique($expected));
            $provided = array_values(array_unique(array_map(
                static fn (array $g): int => (int) $g['user_id'],
                $grants,
            )));

            sort($expected);
            sort($provided);

            $missing = array_values(array_diff($expected, $provided));
            $unexpected = array_values(array_diff($provided, $expected));

            if ($missing === [] && $unexpected === []) {
                return;
            }

            throw ValidationException::withMessages([
                'grants' => [
                    'Vault grants are incomplete.',
                ],
            ])->status(422)->setErrorBag('incomplete_grants');
        };

        if (DB::transactionLevel() > 0) {
            $run();

            return;
        }

        DB::transaction($run);
    }

    /**
     * Promote $user to project owner and materialise access rows. The
     * caller must supply wrapped keys for every existing vault inside the
     * project. Runs inside a transaction so the completeness check and the
     * subsequent writes share an owner snapshot.
     *
     * @param  array<int, array{vault_id: int, encrypted_key: string}>  $vaultGrants
     */
    public function materializeProjectOwnerAccess(User $granter, User $user, Project $project, array $vaultGrants): void
    {
        DB::transaction(function () use ($granter, $user, $project, $vaultGrants): void {
            // The completeness check below expects the new owner to already
            // be in the expected set, so pass them as `extraRecipient` for
            // the vault grants list we're about to materialise.
            $this->enforceVaultGrantCompleteness(
                $project->id,
                array_map(
                    static fn (array $g): array => ['user_id' => $user->id],
                    $vaultGrants,
                ),
                extraRecipient: $user->id,
            );

            $this->grant($granter, $user, $project, MemberRole::Owner);

            foreach ($vaultGrants as $grant) {
                $vault = Vault::query()->where('project_id', $project->id)->whereKey($grant['vault_id'])->first();

                if ($vault === null) {
                    throw new InvalidArgumentException('Vault does not belong to this project.');
                }

                $this->grant(
                    $granter,
                    $user,
                    $vault,
                    MemberRole::Owner,
                    encryptedKey: $grant['encrypted_key'],
                );
            }
        });
    }

    /**
     * Initial key wrap for a freshly-created vault. Runs exactly once per
     * vault, immediately after `POST /projects/{id}/vaults`. Subsequent
     * key changes go through {@see rotateVaultKey}.
     *
     * Client responsibilities before calling:
     *   1. Generate a fresh symmetric vault key.
     *   2. For every authorized member (including the actor), wrap the
     *      new vault key under their RSA public key and include it in $grants.
     *   3. Encrypt every existing credential in the vault with the new
     *      vault key and include the ciphertext + iv in $credentials
     *      (key_version will be set to 1).
     *
     * Server responsibilities:
     *   - Reject if any resource_keys row already exists for this vault
     *     (409 — use rotate-key instead).
     *   - Require an Owner role on the vault (checked by caller via Gate).
     *   - Enforce set-equality between $grants.user_id and the current set
     *     of users with any resource_permissions row pointing at the vault
     *     (direct or project-level). Missing or unexpected recipients → 422.
     *   - Enforce set-equality between $credentials.id and the full set of
     *     credentials currently in the vault. Missing or unknown ids → 422.
     *   - Atomically insert resource_keys rows at version=1 and rewrite
     *     every credential's ciphertext + set key_version=1.
     *   - Write one AuditAction::VaultMigrated row.
     *
     * @param  array<int, array{user_id: int, encrypted_key: string}>  $grants
     * @param  array<int, array{id: int, encrypted_data: string, iv: string}>  $credentials
     */
    public function migrateVault(User $actor, Vault $vault, array $grants, array $credentials): void
    {
        // Idempotent retry path. If the request matches the state on
        // disk *exactly*, return silently — that's a network retry,
        // not a double-migrate. If the on-disk state diverges from
        // the request, 409 with the diff so the client can
        // distinguish a true conflict from a stuck retry loop.
        //
        // Without this, a client that lost its response on the wire
        // would 409 forever on retry and the user would be unable to
        // ever write a credential to that vault. We picked this fix
        // over a permission-side state delete because the recipient
        // public keys + wrap timing must stay client-controlled —
        // the server has no recovery key to reissue.
        $existingKeys = ResourceKey::query()
            ->for(ResourceType::Vault, $vault->id)
            ->where('key_version', 1)
            ->get(['user_id', 'encrypted_key']);

        if ($existingKeys->isNotEmpty()) {
            $current = $existingKeys
                ->map(static fn ($row) => (int) $row->user_id.':'.(string) $row->encrypted_key)
                ->sort()
                ->values()
                ->all();
            $requested = collect($grants)
                ->map(static fn (array $g) => (int) $g['user_id'].':'.(string) $g['encrypted_key'])
                ->sort()
                ->values()
                ->all();

            if ($current === $requested && $credentials === []) {
                // Exact replay of a successful migrate against an
                // empty-credentials vault. Heal `vaults.migrated_at`
                // if it's still NULL from the historical bug where
                // resource_keys was written but the timestamp wasn't.
                // The idempotent replay path is the only place we
                // can pick this up after the fact without a SQL
                // backfill (which still runs separately for the
                // backlog).
                if ($vault->migrated_at === null) {
                    $vault->forceFill(['migrated_at' => now()])->save();
                }

                return;
            }

            throw ValidationException::withMessages([
                'vault' => ['Vault has already been migrated to a per-vault key.'],
            ])->status(409);
        }

        DB::transaction(function () use ($actor, $vault, $grants, $credentials): void {
            // REPEATABLE READ so the authorized-user snapshot we assert
            // against cannot drift while we write the new plane. Can only
            // be set on the outermost transaction — when this service is
            // called from inside an existing transaction (tests, nested
            // callers) we skip it and rely on the surrounding isolation.
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            $this->assertVaultGrantRecipients($vault, $grants);
            $this->assertVaultCredentialSet($vault, $credentials);

            // Insert wrapped keys at version 1. The unique constraint on
            // (resource_type, resource_id, user_id, key_version) means a
            // second call with the same payload would fail hard — which is
            // what we want for a once-per-vault migration.
            foreach ($grants as $grant) {
                ResourceKey::create([
                    'resource_type' => ResourceType::Vault->value,
                    'resource_id' => $vault->id,
                    'project_id' => $vault->project_id,
                    'user_id' => (int) $grant['user_id'],
                    'encrypted_key' => $grant['encrypted_key'],
                    'key_version' => 1,
                ]);
            }

            // Rewrite every credential with the freshly wrapped ciphertext.
            // Empty-vault initial wrap is a valid happy path: the
            // set-equality check passed with 0 == 0, and there is
            // nothing to rewrite.
            if ($credentials !== []) {
                $byId = [];
                foreach ($credentials as $credential) {
                    $byId[(int) $credential['id']] = $credential;
                }

                Credential::query()
                    ->where('vault_id', $vault->id)
                    ->whereNull('deleted_at')
                    ->chunkById(200, function ($chunk) use ($byId): void {
                        foreach ($chunk as $credential) {
                            if (! isset($byId[$credential->id])) {
                                // Defensive: the set-equality check
                                // should have caught this already, but
                                // a race between the check and the
                                // iteration (new credential inserted
                                // mid-transaction) would otherwise
                                // throw an undefined-offset warning.
                                continue;
                            }
                            $payload = $byId[$credential->id];
                            $credential->forceFill([
                                'encrypted_data' => $payload['encrypted_data'],
                                'iv' => $payload['iv'],
                                'key_version' => 1,
                            ])->save();
                        }
                    });
            }

            // Stamp `vaults.migrated_at` in the SAME transaction so
            // the timestamp + resource_keys row + audit log are all
            // committed together. The client uses `migrated_at` as
            // the canonical "vault is keyed" signal (resource-tree
            // badge, grant checkbox, share filters); leaving it NULL
            // here is the bug this commit fixes. The DB column has
            // a DEFAULT now() but we cannot rely on it — rows that
            // existed before the ALTER TABLE added the column were
            // left NULL, and `Vault::create` paths that don't pass
            // `migrated_at` would inherit whatever the schema
            // happened to apply at table-create time on this
            // deployment.
            $vault->forceFill(['migrated_at' => now()])->save();

            $this->audit->record(
                actor: $actor,
                action: AuditAction::VaultMigrated,
                projectId: $vault->project_id,
                resourceType: ResourceType::Vault,
                resourceId: $vault->id,
                metadata: [
                    'key_version' => 1,
                    'credential_count' => count($credentials),
                    'grant_count' => count($grants),
                ],
            );
        });
    }

    /**
     * Rotate the key of an already-migrated vault. The client generates a
     * fresh symmetric key, re-wraps it for every authorized member, and
     * re-encrypts every credential. All writes land at `key_version = N+1`
     * where N is the highest existing key_version on the vault.
     *
     * Old (N and earlier) rows in resource_keys are deleted at the end of
     * the transaction — there is exactly one live version per vault at any
     * point in time. Credentials that were already at key_version N have
     * their ciphertext overwritten in-place.
     *
     * @param  array<int, array{user_id: int, encrypted_key: string}>  $grants
     * @param  array<int, array{id: int, encrypted_data: string, iv: string}>  $credentials
     */
    public function rotateVaultKey(User $actor, Vault $vault, int $expectedCurrentVersion, array $grants, array $credentials): int
    {
        $hasInitialWrap = ResourceKey::query()
            ->for(ResourceType::Vault, $vault->id)
            ->exists();

        if (! $hasInitialWrap) {
            throw ValidationException::withMessages([
                'vault' => ['Vault must be migrated before it can be rotated.'],
            ])->status(409);
        }

        return DB::transaction(function () use ($actor, $vault, $expectedCurrentVersion, $grants, $credentials): int {
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }

            // Optimistic-concurrency guard: the client tells us which
            // version it *thinks* is current. If another rotation landed
            // in the meantime, bail out before we clobber their work.
            $currentVersion = (int) ResourceKey::query()
                ->for(ResourceType::Vault, $vault->id)
                ->max('key_version');

            if ($currentVersion !== $expectedCurrentVersion) {
                throw ValidationException::withMessages([
                    'expected_current_version' => [
                        "Expected current key_version {$expectedCurrentVersion}, got {$currentVersion}.",
                    ],
                ])->status(409);
            }

            $newVersion = $currentVersion + 1;

            $this->assertVaultGrantRecipients($vault, $grants);
            $this->assertVaultCredentialSet($vault, $credentials);

            foreach ($grants as $grant) {
                ResourceKey::create([
                    'resource_type' => ResourceType::Vault->value,
                    'resource_id' => $vault->id,
                    'project_id' => $vault->project_id,
                    'user_id' => (int) $grant['user_id'],
                    'encrypted_key' => $grant['encrypted_key'],
                    'key_version' => $newVersion,
                ]);
            }

            $byId = [];
            foreach ($credentials as $credential) {
                $byId[(int) $credential['id']] = $credential;
            }

            Credential::query()
                ->where('vault_id', $vault->id)
                ->whereNull('deleted_at')
                ->chunkById(200, function ($chunk) use ($byId, $newVersion): void {
                    foreach ($chunk as $credential) {
                        $payload = $byId[$credential->id];
                        $credential->forceFill([
                            'encrypted_data' => $payload['encrypted_data'],
                            'iv' => $payload['iv'],
                            'key_version' => $newVersion,
                        ])->save();
                    }
                });

            // Drop every key_version strictly older than the new one. This
            // is the "all members must be re-invited during the rotation
            // window" rule from CLAUDE.md §6.5 — anyone who wasn't included
            // in $grants has just lost vault access.
            ResourceKey::query()
                ->for(ResourceType::Vault, $vault->id)
                ->where('key_version', '<', $newVersion)
                ->delete();

            // Cascade: any pending workspace invitation that staged a
            // wrapped key for this vault is now holding stale
            // ciphertext. Flag those rows so admins see a "rotated
            // keys" warning on the pending list and accept-time drops
            // the stale grant with a friendly warning (spec §6.3).
            DB::table('workspace_invitation_vault_keys')
                ->where('vault_id', $vault->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            DB::table('workspace_invitation_resource_grants')
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->whereNull('superseded_at')
                ->update(['superseded_at' => now()]);

            $this->audit->record(
                actor: $actor,
                action: AuditAction::ResourceRotated,
                projectId: $vault->project_id,
                resourceType: ResourceType::Vault,
                resourceId: $vault->id,
                metadata: [
                    'previous_key_version' => $currentVersion,
                    'key_version' => $newVersion,
                    'credential_count' => count($credentials),
                    'grant_count' => count($grants),
                ],
            );

            return $newVersion;
        });
    }

    /**
     * Load the current-version wrapped vault key for $user across a batch
     * of vault IDs. Single query, keyed by vault_id. Returns only vaults
     * for which $user has a live resource_keys row at the vault's highest
     * key_version — unmigrated vaults and vaults the user has no key for
     * are simply absent from the result map.
     *
     * Used by VaultController to avoid an N+1 when embedding my_wrapped_key
     * on list responses.
     *
     * @param  array<int, int>  $vaultIds
     * @return array<int, array{encrypted_key: string, key_version: int}>
     */
    public function wrappedVaultKeysFor(User $user, array $vaultIds): array
    {
        if ($vaultIds === []) {
            return [];
        }

        // One row per (vault_id) — the max-versioned key for this user.
        // DISTINCT ON is the idiomatic Postgres way to do this without a
        // self-join or window function.
        $rows = ResourceKey::query()
            ->select(['resource_id', 'encrypted_key', 'key_version'])
            ->where('resource_type', ResourceType::Vault->value)
            ->where('user_id', $user->id)
            ->whereIn('resource_id', $vaultIds)
            ->orderBy('resource_id')
            ->orderByDesc('key_version')
            ->get()
            ->unique('resource_id');

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->resource_id] = [
                'encrypted_key' => (string) $row->encrypted_key,
                'key_version' => (int) $row->key_version,
            ];
        }

        return $out;
    }

    /**
     * Set-equality check for vault grant recipients: the user IDs in
     * $grants must exactly match the set of users who currently have any
     * resource_permissions row granting access to $vault (direct row on
     * the vault itself, or a project-level row that cascades down).
     *
     * @param  array<int, array{user_id: int}>  $grants
     */
    private function assertVaultGrantRecipients(Vault $vault, array $grants): void
    {
        $expected = ResourcePermission::query()
            ->where(function ($q) use ($vault): void {
                $q->where(function ($inner) use ($vault): void {
                    $inner->where('resource_type', ResourceType::Vault->value)
                        ->where('resource_id', $vault->id);
                })->orWhere(function ($inner) use ($vault): void {
                    $inner->where('resource_type', ResourceType::Project->value)
                        ->where('resource_id', $vault->project_id);
                });
            })
            ->pluck('user_id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        // Project + workspace owners are implicit Owners on every
        // resource in the project / workspace respectively; they
        // must always receive a wrapped key even if their explicit
        // resource_permissions row was deleted or never written.
        // ProjectBootstrapper writes both grants up-front, but the
        // implicit add stays as defense-in-depth so a backfilled or
        // hand-tampered DB still passes set-equality.
        $projectRow = Project::query()
            ->whereKey($vault->project_id)
            ->select(['owner_id', 'organisation_id'])
            ->first();

        if ($projectRow !== null) {
            $expected[] = (int) $projectRow->owner_id;

            $workspaceOwnerId = (int) \App\Models\Identity\Organisation::query()
                ->whereKey($projectRow->organisation_id)
                ->value('owner_id');
            if ($workspaceOwnerId > 0) {
                $expected[] = $workspaceOwnerId;
            }
        }

        $expected = array_values(array_unique($expected));
        $provided = array_values(array_unique(array_map(
            static fn (array $g): int => (int) $g['user_id'],
            $grants,
        )));

        sort($expected);
        sort($provided);

        $missing = array_values(array_diff($expected, $provided));
        $unexpected = array_values(array_diff($provided, $expected));

        if ($missing === [] && $unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            'grants' => ['Vault grants do not match the set of authorized members.'],
            'grants.missing' => $missing,
            'grants.unexpected' => $unexpected,
        ])->status(422);
    }

    /**
     * Set-equality check for the credential list: ids in $credentials must
     * exactly match the ids of non-deleted credentials currently in the vault.
     *
     * @param  array<int, array{id: int}>  $credentials
     */
    private function assertVaultCredentialSet(Vault $vault, array $credentials): void
    {
        $expected = Credential::query()
            ->where('vault_id', $vault->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();

        $provided = array_values(array_unique(array_map(
            static fn (array $c): int => (int) $c['id'],
            $credentials,
        )));

        sort($expected);
        sort($provided);

        $missing = array_values(array_diff($expected, $provided));
        $unexpected = array_values(array_diff($provided, $expected));

        if ($missing === [] && $unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            'credentials' => ['Credential list does not match the vault contents.'],
            'credentials.missing' => $missing,
            'credentials.unexpected' => $unexpected,
        ])->status(422);
    }

    /**
     * Only vault and project rows are allowed in resource_keys (DB CHECK).
     * Reject any attempt to pass an encrypted key alongside a board or
     * bucket grant before we hit the database.
     */
    private function assertEncryptedKeyShape(ResourceType $type, ?string $encryptedKey): void
    {
        if ($encryptedKey === null) {
            return;
        }

        if ($type !== ResourceType::Project && $type !== ResourceType::Vault) {
            throw new InvalidArgumentException(
                'encrypted_key is only valid for project or vault grants, got '.$type->value,
            );
        }
    }

    private function projectOf(Model $resource): ?Project
    {
        return match (true) {
            $resource instanceof Project => $resource,
            $resource instanceof TaskBoard,
            $resource instanceof Vault,
            $resource instanceof ExpenseBucket,
            $resource instanceof Doc => Project::find($resource->project_id),
            default => null,
        };
    }

    private function resourceTypeOf(Model $resource): ResourceType
    {
        return match (true) {
            $resource instanceof Project => ResourceType::Project,
            $resource instanceof TaskBoard => ResourceType::Board,
            $resource instanceof Vault => ResourceType::Vault,
            $resource instanceof ExpenseBucket => ResourceType::Bucket,
            $resource instanceof Doc => ResourceType::Doc,
            default => throw new \InvalidArgumentException('Unsupported resource: '.$resource::class),
        };
    }

    private function baseQueryFor(ResourceType $type): Builder
    {
        return match ($type) {
            ResourceType::Board => TaskBoard::query(),
            ResourceType::Vault => Vault::query(),
            ResourceType::Bucket => ExpenseBucket::query(),
            ResourceType::Doc => Doc::query(),
            ResourceType::Project => Project::query(),
        };
    }
}
