<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Docs\StoreDocMemberRequest;
use App\Http\Requests\Docs\UpdateDocMemberRequest;
use App\Http\Resources\ResourceMemberResource;
use App\Models\Docs\Doc;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-doc membership (Pattern B). Docs have no crypto plane; a grant
 * is a single `resource_permissions` row at resource_type='doc'.
 * Mirrors BoardMemberController / BucketMemberController — only
 * difference is the `store` payload takes `user_id` directly (per
 * the docs spec) instead of an email lookup.
 */
class DocMemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Doc $doc): AnonymousResourceCollection
    {
        $this->authorize('share', $doc);

        $members = ResourcePermission::query()
            ->with('user')
            ->where('resource_type', ResourceType::Doc->value)
            ->where('resource_id', $doc->id)
            ->orderBy('created_at')
            ->get();

        return ResourceMemberResource::collection($members);
    }

    public function store(StoreDocMemberRequest $request, Doc $doc): JsonResponse
    {
        $this->authorize('share', $doc);

        /** @var User $target */
        $target = User::query()->whereKey((int) $request->input('user_id'))->firstOrFail();

        if ($target->id === (int) $doc->project->original_owner_id) {
            return response()->json([
                'message' => 'The project creator already has implicit access to every resource.',
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($target->id === (int) $doc->project->organisation?->owner_id) {
            return response()->json([
                'message' => 'The workspace owner already has implicit access to every resource in their workspace.',
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        $role = MemberRole::from($request->string('role')->toString());
        $permission = $this->perms->grant($request->user(), $target, $doc, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ], 201);
    }

    public function update(UpdateDocMemberRequest $request, Doc $doc, User $user): JsonResponse
    {
        $this->authorize('share', $doc);

        $role = MemberRole::from($request->string('role')->toString());
        $permission = $this->perms->grant($request->user(), $user, $doc, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ]);
    }

    public function destroy(Request $request, Doc $doc, User $user): JsonResponse
    {
        $this->authorize('share', $doc);

        if ($this->perms->wouldLeaveResourceOwnerless($user, $doc)) {
            return response()->json([
                'message' => 'Cannot remove the last owner of this doc.',
                'code' => 'cannot_remove_last_owner',
            ], 422);
        }

        $this->perms->revoke($user, $doc, $request->user());

        return response()->json(status: 204);
    }
}
