<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Tasks\TaskItem;
use App\Models\User;
use App\Services\Activity\ActivityService;
use App\Services\Notifications\NotificationService;
use App\Services\Permissions\Abilities;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskAssigneeController extends Controller
{
    public function __construct(
        private readonly ActivityService $activity,
        private readonly PermissionService $perms,
        private readonly NotificationService $notifications,
    ) {}

    public function store(Request $request, TaskItem $taskItem, User $user): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        // The assignee must have at least read access to the board — either
        // via a project-level grant (Pattern A) or a direct grant on this
        // specific board (Pattern B). PermissionService resolves both.
        if (! $this->perms->can($user, Abilities::VIEW, $taskItem->column->board)) {
            return response()->json(['message' => 'User does not have access to this board.'], 422);
        }

        $alreadyAssigned = $taskItem->assignees()->where('users.id', $user->id)->exists();

        $taskItem->assignees()->syncWithoutDetaching([$user->id]);

        $this->activity->record(
            $request->user(),
            $taskItem,
            ActivityAction::Assigned,
            meta: ['user_id' => $user->id],
        );

        // Notify the assignee — but only on a fresh assignment, not on
        // an idempotent re-PUT. The NotificationService already skips
        // self-assignment (spec §2), so no explicit check here.
        if (! $alreadyAssigned) {
            [$project, $workspace] = $this->notifications->projectContextFor($taskItem);
            $actor = $request->user();

            $this->notifications->create(
                userId: $user->id,
                type: NotificationType::TaskAssigned,
                title: ($actor?->name ?? 'Someone').' assigned you to "'.$taskItem->title.'"',
                actor: $actor,
                workspace: $workspace,
                project: $project,
                resourceType: 'task',
                resourceId: $taskItem->id,
                metadata: ['board_id' => $taskItem->column->board_id],
            );
        }

        return response()->json(status: 204);
    }

    public function destroy(Request $request, TaskItem $taskItem, User $user): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $taskItem->assignees()->detach($user->id);

        $this->activity->record(
            $request->user(),
            $taskItem,
            ActivityAction::Unassigned,
            meta: ['user_id' => $user->id],
        );

        return response()->json(status: 204);
    }
}
