<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vault\StoreVaultMemberRequest;
use App\Http\Requests\Vault\UpdateVaultMemberRequest;
use App\Http\Resources\ResourceMemberResource;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-vault membership (Pattern B). Grants live in `resource_permissions`
 * at resource_type='vault'; the server pairs every grant with a matching
 * `resource_keys` row carrying the client-wrapped vault key at the
 * current key_version. The server never sees or derives key material.
 */
class VaultMemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Vault $vault): AnonymousResourceCollection
    {
        $this->authorize('share', $vault);

        $members = ResourcePermission::query()
            ->with('user')
            ->where('resource_type', ResourceType::Vault->value)
            ->where('resource_id', $vault->id)
            ->orderBy('created_at')
            ->get();

        return ResourceMemberResource::collection($members);
    }

    public function store(StoreVaultMemberRequest $request, Vault $vault): JsonResponse
    {
        $this->authorize('share', $vault);

        $email = $request->string('email')->toString();
        /** @var User $target */
        $target = User::query()->where('email', $email)->firstOrFail();

        if ($target->id === (int) $vault->project->original_owner_id) {
            return response()->json([
                'message' => 'The project creator already has implicit access to every resource.',
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($target->id === (int) $vault->project->organisation?->owner_id) {
            return response()->json([
                'message' => 'The workspace owner already has implicit access to every resource in their workspace.',
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        if ($target->public_key === null) {
            return response()->json([
                'message' => 'Invitee has not completed master-password setup.',
            ], 422);
        }

        $role = MemberRole::from($request->string('role')->toString());
        $encryptedKey = $request->string('encrypted_key')->toString();

        $permission = $this->perms->grant(
            $request->user(),
            $target,
            $vault,
            $role,
            encryptedKey: $encryptedKey,
        );

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ], 201);
    }

    public function update(UpdateVaultMemberRequest $request, Vault $vault, User $user): JsonResponse
    {
        $this->authorize('share', $vault);

        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $user, $vault, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ]);
    }

    public function destroy(Request $request, Vault $vault, User $user): JsonResponse
    {
        $this->authorize('share', $vault);

        if ($this->perms->wouldLeaveResourceOwnerless($user, $vault)) {
            return response()->json([
                'message' => 'Cannot remove the last owner of this vault.',
                'code' => 'cannot_remove_last_owner',
            ], 422);
        }

        $this->perms->revoke($user, $vault, $request->user());

        return response()->json(status: 204);
    }
}