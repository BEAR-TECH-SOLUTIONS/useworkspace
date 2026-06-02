<?php

namespace App\Services\Workspaces;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\DeferredAccessGrant;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vault-only finalisation of provisioning intent.
 *
 * The provisioning flow now applies every non-vault grant
 * (project-level role, boards, buckets) at provision time — only vault
 * access waits for a finalise call because wrapping keys requires the
 * new user's RSA public key. Each deferred row stores:
 *   - `mode`      — 'project' (user already has a cascading project row,
 *                   only resource_keys are missing) or 'resources'
 *                   (per-vault resource_permissions still need to be
 *                   written too).
 *   - `resources` — `[{vault_id, role?}, ...]`. `role` is present only
 *                   on resources-mode entries; project-mode rows
 *                   inherit their role from the existing project
 *                   permission row.
 *
 * Authorization: workspace admin/owner OR project owner on the target.
 * Target user must have `master_password_set = true` — otherwise
 * there's no public key to wrap against.
 */
class DeferredAccessService
{
    public function __construct(private readonly PermissionService $perms) {}

    /**
     * Apply a single deferred row. Returns `{ project_id, vaults_applied }`.
     * Deletes the row on success.
     *
     * @param  array<int, array{vault_id:int, encrypted_key:string, key_version:int}>  $vaultKeys
     * @return array<string, mixed>
     */
    public function finalize(DeferredAccessGrant $deferred, User $actor, array $vaultKeys): array
    {
        $this->assertCallerCanFinalize($deferred, $actor);

        $user = User::query()->whereKey($deferred->user_id)->firstOrFail();
        if (! $user->hasMasterPassword()) {
            throw ValidationException::withMessages([
                'user' => ["User hasn't set up their master password yet."],
                'code' => ['user_not_ready'],
            ])->status(422);
        }

        $project = Project::query()->whereKey($deferred->project_id)->firstOrFail();

        return DB::transaction(function () use ($deferred, $user, $project, $actor, $vaultKeys): array {
            $mode = (string) $deferred->mode;

            // Filter to vault-only entries — legacy rows predating the
            // split-provisioning refactor could carry non-vault entries
            // like `{type:'board',id:N,role:'editor'}`. Finalise is now
            // exclusively about wrapping vault keys, so drop anything
            // that isn't shaped like `{vault_id:int, role?:string}`.
            $pending = array_values(array_filter(
                (array) ($deferred->resources ?? []),
                static fn ($r): bool => is_array($r) && isset($r['vault_id']),
            ));

            // Set-equality between the stashed vault ids and the
            // wrapped keys the caller provided. One missing key would
            // leave the user with a resource_permissions row but no
            // key to decrypt — breaks the "access = readable" rule.
            $this->assertVaultKeysCoverPending($pending, $vaultKeys, $actor);

            $vaultKeysById = [];
            foreach ($vaultKeys as $vk) {
                $vaultKeysById[(int) $vk['vault_id']] = $vk;
            }

            foreach ($pending as $entry) {
                $vaultId = (int) $entry['vault_id'];
                $vk = $vaultKeysById[$vaultId];

                // mode='resources' rows still need the vault
                // resource_permissions row; mode='project' rows
                // inherit access from the already-applied project row.
                if ($mode === 'resources') {
                    $role = MemberRole::from((string) $entry['role']);
                    ResourcePermission::updateOrCreate(
                        [
                            'user_id' => $user->id,
                            'resource_type' => ResourceType::Vault->value,
                            'resource_id' => $vaultId,
                        ],
                        [
                            'project_id' => $project->id,
                            'role' => $role->value,
                            'granted_by' => $actor->id,
                        ],
                    );
                }

                $this->writeWrappedKey($project, $user, $vaultId, (string) $vk['encrypted_key']);
            }

            $deferred->delete();

            return [
                'project_id' => (int) $project->id,
                'vaults_applied' => count($pending),
            ];
        });
    }

    private function assertCallerCanFinalize(DeferredAccessGrant $deferred, User $actor): void
    {
        $project = Project::query()->whereKey($deferred->project_id)->first();
        if ($project !== null && $this->perms->can($actor, Abilities::SHARE, $project)) {
            return;
        }

        $workspace = \App\Models\Identity\Organisation::query()->whereKey($deferred->workspace_id)->first();
        if ($workspace === null) {
            abort(403);
        }

        if ($workspace->owner_id === $actor->id) {
            return;
        }

        $isAdmin = \App\Models\Identity\OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->where('role', \App\Enums\OrganisationRole::Admin->value)
            ->exists();

        if (! $isAdmin) {
            abort(403);
        }
    }

    /**
     * Set-equality between the deferred vault list and the wrapped
     * keys the caller supplied. Also runs the per-vault key_version +
     * actor-decrypt check shared with the invitation flow.
     *
     * @param  array<int, array{vault_id:int, role?:string}>  $pending
     * @param  array<int, array{vault_id:int, encrypted_key:string, key_version:int}>  $vaultKeys
     */
    private function assertVaultKeysCoverPending(array $pending, array $vaultKeys, User $actor): void
    {
        $expected = array_values(array_map(static fn (array $p): int => (int) $p['vault_id'], $pending));
        $provided = array_values(array_map(static fn (array $v): int => (int) $v['vault_id'], $vaultKeys));

        sort($expected);
        sort($provided);

        $missing = array_values(array_diff($expected, $provided));
        $unknown = array_values(array_diff($provided, $expected));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'vault_keys' => ['Missing wrapped keys for vault ids: '.implode(',', $missing)],
                'code' => ['missing_vault_keys'],
            ])->status(422);
        }

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'vault_keys' => ['Unknown vault ids: '.implode(',', $unknown)],
                'code' => ['unknown_vault'],
            ])->status(422);
        }

        foreach ($vaultKeys as $vk) {
            $this->assertActorHoldsCurrentVaultKey((int) $vk['vault_id'], $actor, $vk);
        }
    }

    /**
     * @param  array{vault_id:int, encrypted_key:string, key_version:int}  $vk
     */
    private function assertActorHoldsCurrentVaultKey(int $vaultId, User $actor, array $vk): void
    {
        $currentVersion = (int) ResourceKey::query()
            ->for(ResourceType::Vault, $vaultId)
            ->max('key_version');

        if ($currentVersion > 0 && (int) $vk['key_version'] !== $currentVersion) {
            throw ValidationException::withMessages([
                'vault_keys' => ["Vault #{$vaultId} key_version is stale (expected {$currentVersion})."],
                'code' => ['vault_key_rotated'],
            ])->status(422);
        }

        $actorHolds = ResourceKey::query()
            ->where('resource_type', ResourceType::Vault->value)
            ->where('resource_id', $vaultId)
            ->where('user_id', $actor->id)
            ->where('key_version', $currentVersion > 0 ? $currentVersion : 1)
            ->exists();

        if (! $actorHolds) {
            throw ValidationException::withMessages([
                'vault_keys' => ["You don't hold the current key for vault #{$vaultId}."],
                'code' => ['cannot_grant_resource'],
            ])->status(403);
        }
    }

    private function writeWrappedKey(Project $project, User $user, int $vaultId, string $encryptedKey): void
    {
        $currentVersion = (int) ResourceKey::query()
            ->for(ResourceType::Vault, $vaultId)
            ->max('key_version');
        $targetVersion = $currentVersion > 0 ? $currentVersion : 1;

        ResourceKey::updateOrCreate(
            [
                'resource_type' => ResourceType::Vault->value,
                'resource_id' => $vaultId,
                'user_id' => $user->id,
                'key_version' => $targetVersion,
            ],
            [
                'project_id' => $project->id,
                'encrypted_key' => $encryptedKey,
            ],
        );
    }
}
