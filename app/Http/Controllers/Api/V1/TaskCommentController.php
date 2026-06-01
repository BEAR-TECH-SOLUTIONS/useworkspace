<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\NotificationType;
use App\Events\Tasks\TaskCommentCreated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskCommentRequest;
use App\Http\Requests\Tasks\UpdateTaskCommentRequest;
use App\Http\Resources\Tasks\TaskCommentResource;
use App\Models\Tasks\TaskComment;
use App\Models\Tasks\TaskItem;
use App\Services\Activity\ActivityService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskCommentController extends Controller
{
    public function __construct(
        private readonly ActivityService $activity,
        private readonly NotificationService $notifications,
    ) {}

    public function index(TaskItem $taskItem): AnonymousResourceCollection
    {
        $this->authorize('view', $taskItem->column->board);

        $comments = TaskComment::query()
            ->with('author')
            ->where('task_item_id', $taskItem->id)
            ->orderBy('created_at')
            ->get();

        return TaskCommentResource::collection($comments);
    }

    public function store(StoreTaskCommentRequest $request, TaskItem $taskItem): JsonResponse
    {
        $board = $taskItem->column->board;
        $this->authorize('update', $board);

        $user = $request->user();

        $comment = DB::transaction(function () use ($request, $taskItem, $user): TaskComment {
            $comment = TaskComment::create([
                'task_item_id' => $taskItem->id,
                'user_id' => $user->id,
                'parent_id' => $request->input('parent_id'),
                'body' => $request->string('body')->toString(),
            ]);

            $this->activity->record(
                $user,
                $taskItem,
                ActivityAction::Commented,
                meta: ['comment_id' => $comment->id],
            );

            return $comment;
        });

        TaskCommentCreated::dispatch($board->id, $comment);

        $assigneeIds = $taskItem->assignees()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all();
        if ($assigneeIds !== []) {
            [$project, $workspace] = $this->notifications->projectContextFor($taskItem);

            $this->notifications->createMany(
                userIds: $assigneeIds,
                type: NotificationType::TaskCommented,
                title: ($user?->name ?? 'Someone').' commented on "'.$taskItem->title.'"',
                body: $this->notifications->truncateCommentBody($comment->body),
                actor: $user,
                workspace: $workspace,
                project: $project,
                resourceType: 'task',
                resourceId: $taskItem->id,
                metadata: [
                    'board_id' => $board->id,
                    'comment_id' => $comment->id,
                ],
            );
        }

        return response()->json([
            'comment' => new TaskCommentResource($comment->load('author')),
        ], 201);
    }

    public function update(UpdateTaskCommentRequest $request, TaskComment $taskComment): JsonResponse
    {
        $task = $taskComment->task()->firstOrFail();
        $this->authorize('view', $task->column->board);

        if ($taskComment->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You can only edit your own comments.'], 403);
        }

        $taskComment->update(['body' => $request->string('body')->toString()]);

        return response()->json([
            'comment' => new TaskCommentResource($taskComment->refresh()->load('author')),
        ]);
    }

    public function destroy(Request $request, TaskComment $taskComment): JsonResponse
    {
        $task = $taskComment->task()->firstOrFail();
        $board = $task->column->board;
        $this->authorize('view', $board);

        $user = $request->user();

        // Comment authors can delete their own; project owners can delete any.
        if ($taskComment->user_id !== $user->id) {
            $project = $board->project;
            if ($project === null || $project->owner_id !== $user->id) {
                return response()->json(['message' => 'You can only delete your own comments.'], 403);
            }
        }

        $taskComment->delete();

        return response()->json(status: 204);
    }
}
