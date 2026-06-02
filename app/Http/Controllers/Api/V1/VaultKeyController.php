<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\MigrateVaultKeyRequest;
use App\Http\Requests\Vault\RotateVaultKeyRequest;
use App\Http\Requests\Vault\WrapVaultKeyRequest;
use App\Http\Resources\Vault\VaultResource;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\AuditLogger;
use App\Services\Permissions\PermissionService;
use App\Services\Sharing\ShareLinkRevoker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Vault key lifecycle endpoints (CLAUDE.md §6.5).
 *
 * Both actions are owner-only and 2FA-gated at the route layer. The server
 * never sees the plaintext vault key — the client uploads wrapped keys and
 * already-re-encrypted credential ciphertext, and this controller just
 * delegates to PermissionService for the atomic swap + audit write.
 */
class VaultKeyController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly AuditLogger $audit,
        private readonly ShareLinkRevoker $shareRevoker,
    ) {}

    public function migrate(MigrateVaultKeyRequest $request, Vault $vault): JsonResponse
    {
        $this->authorize('share', $vault);

        // Attach an explicit `code` to any unhandled failure so the
        // client can distinguish bare "Server Error" from a
        // domain-signalled 409/422. Laravel's own typed exceptions
        // (validation / authorization) keep their native status; only
        // truly unexpected throwables fall through to the generic 500
        // and the logger below ensures prod sees them even when the
        // default log channel is misconfigured.
        try {
            $this->perms->migrateVault(
                actor: $request->user(),
                vault: $vault,
                grants: $request->input('grants', []),
                credentials: $request->input('credentials', []),
            );
        } catch (ValidationException|AuthorizationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('vaults.migrate-key failed', [
                'vault_id' => $vault->id,
                'user_id' => $request->user()->id,
                'grant_count' => count((array) $request->input('grants', [])),
                'credential_count' => count((array) $request->input('credentials', [])),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Vault migration failed.',
                'code' => 'vault_migrate_failed',
            ], 500);
        }

        return response()->json([
            'vault' => new VaultResource($this->withWrappedKey($request->user(), $vault->refresh())),
            'key_version' => 1,
        ]);
    }

    public function rotate(RotateVaultKeyRequest $request, Vault $vault): JsonResponse
    {
        $this->authorize('share', $vault);

        $newVersion = $this->perms->rotateVaultKey(
            actor: $request->user(),
            vault: $vault,
            expectedCurrentVersion: (int) $request->input('expected_current_version'),
            grants: $request->input('grants', []),
            credentials: $request->input('credentials', []),
        );

        // Housekeeping (Universal Share Links plan §10): outstanding
        // credential shares from this vault become visually stale after
        // a key rotation. The share's enc_key is bound to the share
        // password, not the vault key, so this is policy not crypto —
        // the recipient could still decrypt with the password if we
        // didn't revoke. Auto-revoke prevents accidental "still works
        // after rotation" surprise.
        $this->shareRevoker->revokeAllForVault($vault, 'vault_key_rotated', $request->user());

        return response()->json([
            'vault' => new VaultResource($this->withWrappedKey($request->user(), $vault->refresh())),
            'key_version' => $newVersion,
        ]);
    }

    /**
     * POST /vaults/{vault}/wrap-key
     *
     * Add a resource_keys row for a Pattern A user who has project-level
     * cascading access but no wrapped key for this vault yet. Idempotent.
     */
    public function wrapKey(WrapVaultKeyRequest $request, Vault $vault): JsonResponse
    {
        $this->authorize('share', $vault);

        $targetUserId = (int) $request->input('user_id');
        $encryptedKey = $request->string('encrypted_key')->toString();
        $requestedVersion = (int) $request->input('key_version');

        /** @var User $target */
        $target = User::findOrFail($targetUserId);

        // Validate key_version matches the vault's current version.
        $currentVersion = (int) ResourceKey::query()
            ->for(ResourceType::Vault, $vault->id)
            ->max('key_version');

        if ($currentVersion > 0 && $requestedVersion !== $currentVersion) {
            return response()->json([
                'message' => 'Key version mismatch — vault was rotated.',
            ], 409);
        }

        // Validate user has cascading access via project-level row.
        $hasProjectGrant = ResourcePermission::query()
            ->where('user_id', $targetUserId)
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $vault->project_id)
            ->exists();

        $isProjectOwner = $vault->project_id && DB::table('projects')
            ->where('id', $vault->project_id)
            ->where('owner_id', $targetUserId)
            ->exists();

        if (! $hasProjectGrant && ! $isProjectOwner) {
            // Check if they have a direct vault grant — that's Pattern B,
            // they should already have a key from the grant.
            $hasDirectGrant = ResourcePermission::query()
                ->where('user_id', $targetUserId)
                ->where('resource_type', ResourceType::Vault->value)
                ->where('resource_id', $vault->id)
                ->exists();

            if ($hasDirectGrant) {
                return response()->json([
                    'message' => 'User has a direct vault grant (Pattern B) — they should already have a wrapped key from the grant.',
                ], 422);
            }

            return response()->json([
                'message' => 'User does not have cascading access to this vault.',
            ], 422);
        }

        $targetVersion = $currentVersion > 0 ? $currentVersion : 1;

        // Idempotent: updateOrCreate so a second call is a no-op.
        ResourceKey::updateOrCreate(
            [
                'resource_type' => ResourceType::Vault->value,
                'resource_id' => $vault->id,
                'user_id' => $targetUserId,
                'key_version' => $targetVersion,
            ],
            [
                'project_id' => $vault->project_id,
                'encrypted_key' => $encryptedKey,
            ],
        );

        $this->audit->record(
            actor: $request->user(),
            action: AuditAction::ResourceGranted,
            projectId: $vault->project_id,
            resourceType: ResourceType::Vault,
            resourceId: $vault->id,
            targetUserId: $targetUserId,
            metadata: [
                'key_version' => $targetVersion,
                'wrap_key' => true,
            ],
        );

        return response()->json([
            'vault' => new VaultResource($this->withWrappedKey($target, $vault->refresh())),
        ]);
    }

    /**
     * Attach the freshly-written wrapped vault key so the response carries
     * my_wrapped_key instead of requiring the client to re-fetch the vault.
     */
    private function withWrappedKey(\App\Models\User $user, Vault $vault): Vault
    {
        $keys = $this->perms->wrappedVaultKeysFor($user, [$vault->id]);
        $vault->setAttribute(VaultResource::WRAPPED_KEY_ATTR, $keys[$vault->id] ?? null);

        return $vault;
    }
}