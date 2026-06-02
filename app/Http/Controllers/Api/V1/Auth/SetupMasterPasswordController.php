<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\NotificationType;
use App\Enums\OrganisationRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupMasterPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\DeferredAccessGrant;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/auth/master-password
 *
 * Captures the client-side master-password handshake (CLAUDE.md §6.1):
 *   - master_password_salt    — PBKDF2/Argon2id salt
 *   - master_password_verifier — HKDF verifier derived from the master key
 *   - public_key               — RSA-OAEP 4096 SPKI
 *   - encrypted_private_key    — private key wrapped by the master key
 *   - private_key_iv           — 12-byte IV used to wrap the private key
 *
 * This runs exactly once per account. Overwriting the bundle would orphan
 * every resource_keys row that was already wrapped with the old public key,
 * so a second call is rejected with 409.
 */
class SetupMasterPasswordController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function __invoke(SetupMasterPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasMasterPassword()) {
            return response()->json([
                'message' => 'Master password is already configured for this account.',
                'code' => 'master_password_already_set',
            ], 409);
        }

        $user->forceFill([
            'master_password_salt' => $request->string('master_password_salt')->toString(),
            'master_password_verifier' => $request->string('master_password_verifier')->toString(),
            'public_key' => $request->string('public_key')->toString(),
            'encrypted_private_key' => $request->string('encrypted_private_key')->toString(),
            'private_key_iv' => $request->string('private_key_iv')->toString(),
        ])->save();

        $this->notifyAdminsAboutDeferredGrants($user);

        return response()->json([
            'user' => new UserResource($user->refresh()),
        ]);
    }

    /**
     * Deferred-provisioning bridge: once the user's public key lands,
     * fan out `user_ready_for_access` notifications to every admin /
     * owner in any workspace where this user has pending deferred
     * grants. One notification per (workspace, admin) — grouped so
     * a user provisioned to N projects still only produces one ping
     * per admin per workspace.
     */
    private function notifyAdminsAboutDeferredGrants(User $user): void
    {
        $pendingByWorkspace = DeferredAccessGrant::query()
            ->where('user_id', $user->id)
            ->selectRaw('workspace_id, COUNT(*) as pending_count')
            ->groupBy('workspace_id')
            ->get();

        foreach ($pendingByWorkspace as $row) {
            $workspace = Organisation::query()->whereKey((int) $row->workspace_id)->first();
            if ($workspace === null) {
                continue;
            }

            $recipients = $this->adminIdsFor($workspace);
            if ($recipients === []) {
                continue;
            }

            $pendingCount = (int) $row->pending_count;

            $this->notifications->createMany(
                userIds: $recipients,
                type: NotificationType::UserReadyForAccess,
                title: $user->name.' is ready for vault access',
                body: $pendingCount.' project(s) have pending access grants that need vault keys.',
                actor: $user,
                workspace: $workspace,
                project: null,
                resourceType: null,
                resourceId: null,
                metadata: [
                    'user_id' => $user->id,
                    'pending_count' => $pendingCount,
                ],
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function adminIdsFor(Organisation $workspace): array
    {
        $ids = [(int) $workspace->owner_id];

        $admins = OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('role', OrganisationRole::Admin->value)
            ->pluck('user_id');

        foreach ($admins as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }
}
