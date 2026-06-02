<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseBucketMemberRequest;
use App\Http\Requests\Expenses\UpdateExpenseBucketMemberRequest;
use App\Http\Resources\ResourceMemberResource;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourcePermission;
use App\Models\User;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-bucket membership (Pattern B). No crypto plane — a bucket grant is
 * a single row in `resource_permissions` at resource_type='bucket'.
 */
class BucketMemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(ExpenseBucket $expenseBucket): AnonymousResourceCollection
    {
        $this->authorize('share', $expenseBucket);

        $members = ResourcePermission::query()
            ->with('user')
            ->where('resource_type', ResourceType::Bucket->value)
            ->where('resource_id', $expenseBucket->id)
            ->orderBy('created_at')
            ->get();

        return ResourceMemberResource::collection($members);
    }

    public function store(StoreExpenseBucketMemberRequest $request, ExpenseBucket $expenseBucket): JsonResponse
    {
        $this->authorize('share', $expenseBucket);

        $email = $request->string('email')->toString();
        /** @var User $target */
        $target = User::query()->where('email', $email)->firstOrFail();

        if ($target->id === (int) $expenseBucket->project->original_owner_id) {
            return response()->json([
                'message' => 'The project creator already has implicit access to every resource.',
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($target->id === (int) $expenseBucket->project->organisation?->owner_id) {
            return response()->json([
                'message' => 'The workspace owner already has implicit access to every resource in their workspace.',
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $target, $expenseBucket, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ], 201);
    }

    public function update(UpdateExpenseBucketMemberRequest $request, ExpenseBucket $expenseBucket, User $user): JsonResponse
    {
        $this->authorize('share', $expenseBucket);

        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $user, $expenseBucket, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ]);
    }

    public function destroy(Request $request, ExpenseBucket $expenseBucket, User $user): JsonResponse
    {
        $this->authorize('share', $expenseBucket);

        if ($this->perms->wouldLeaveResourceOwnerless($user, $expenseBucket)) {
            return response()->json([
                'message' => 'Cannot remove the last owner of this bucket.',
                'code' => 'cannot_remove_last_owner',
            ], 422);
        }

        $this->perms->revoke($user, $expenseBucket, $request->user());

        return response()->json(status: 204);
    }
}