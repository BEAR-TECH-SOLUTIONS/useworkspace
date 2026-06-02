<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskBoardMemberRequest;
use App\Http\Requests\Tasks\UpdateTaskBoardMemberRequest;
use App\Http\Resources\ResourceMemberResource;
use App\Models\Permissions\ResourcePermission;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Per-board membership (Pattern B). Boards have no crypto plane; grants
 * are a single row in `resource_permissions` at resource_type='board'.
 */
class BoardMemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(TaskBoard $taskBoard): AnonymousResourceCollection
    {
        $this->authorize('share', $taskBoard);

        $members = ResourcePermission::query()
            ->with('user')
            ->where('resource_type', ResourceType::Board->value)
            ->where('resource_id', $taskBoard->id)
            ->orderBy('created_at')
            ->get();

        return ResourceMemberResource::collection($members);
    }

    public function store(StoreTaskBoardMemberRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('share', $taskBoard);

        $email = $request->string('email')->toString();
        /** @var User $target */
        $target = User::query()->where('email', $email)->firstOrFail();

        if ($target->id === (int) $taskBoard->project->original_owner_id) {
            return response()->json([
                'message' => 'The project creator already has implicit access to every resource.',
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($target->id === (int) $taskBoard->project->organisation?->owner_id) {
            return response()->json([
                'message' => 'The workspace owner already has implicit access to every resource in their workspace.',
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $target, $taskBoard, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ], 201);
    }

    public function update(UpdateTaskBoardMemberRequest $request, TaskBoard $taskBoard, User $user): JsonResponse
    {
        $this->authorize('share', $taskBoard);

        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $user, $taskBoard, $role);

        return response()->json([
            'member' => new ResourceMemberResource($permission->load('user')),
        ]);
    }

    public function destroy(Request $request, TaskBoard $taskBoard, User $user): JsonResponse
    {
        $this->authorize('share', $taskBoard);

        if ($this->perms->wouldLeaveResourceOwnerless($user, $taskBoard)) {
            return response()->json([
                'message' => 'Cannot remove the last owner of this board.',
                'code' => 'cannot_remove_last_owner',
            ], 422);
        }

        $this->perms->revoke($user, $taskBoard, $request->user());

        return response()->json(status: 204);
    }
}