<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\NotificationType;
use App\Enums\TaskPriority;
use App\Events\Tasks\TaskItemCreated;
use App\Events\Tasks\TaskItemDeleted;
use App\Events\Tasks\TaskItemMoved;
use App\Events\Tasks\TaskItemUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\MoveTaskItemRequest;
use App\Http\Requests\Tasks\StoreTaskItemRequest;
use App\Http\Requests\Tasks\UpdateTaskItemRequest;
use App\Http\Resources\Tasks\TaskItemResource;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Services\Activity\ActivityService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TaskItemController extends Controller
{
    public function __construct(
        private readonly ActivityService $activity,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Fields on a task that, when changed, fire a `task_updated`
     * notification to the assignees. Mirrors the spec §2 list except
     * `column_id` — that field is routed through the move() endpoint,
     * which has its own notification branch.
     */
    private const NOTIFIABLE_UPDATE_FIELDS = ['title', 'priority', 'due_date', 'is_completed', 'is_archived'];

    public function store(StoreTaskItemRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);

        $column = TaskColumn::query()
            ->where('board_id', $taskBoard->id)
            ->where('id', (int) $request->input('column_id'))
            ->firstOrFail();

        $user = $request->user();

        $task = DB::transaction(function () use ($request, $taskBoard, $column, $user): TaskItem {
            $task = TaskItem::create([
                'project_id' => $taskBoard->project_id,
                'column_id' => $column->id,
                'title' => $request->string('title')->toString(),
                'description' => $request->input('description'),
                'priority' => TaskPriority::from($request->input('priority', TaskPriority::Medium->value)),
                'position' => (float) $request->input('position', $this->nextPosition($column)),
                'due_date' => $request->input('due_date'),
                'created_by' => $user->id,
            ]);

            $this->activity->record($user, $task, ActivityAction::Created);

            return $task;
        });

        TaskItemCreated::dispatch($task->load('column'));

        return response()->json([
            'task' => new TaskItemResource($task),
        ], 201);
    }

    public function show(TaskItem $taskItem): JsonResponse
    {
        $this->authorize('view', $taskItem->column->board);

        $taskItem->load(['labels', 'assignees', 'checklists']);
        $taskItem->loadCount(['comments', 'resourceLinks']);

        return response()->json([
            'task' => new TaskItemResource($taskItem),
        ]);
    }

    public function update(UpdateTaskItemRequest $request, TaskItem $taskItem): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $user = $request->user();
        $original = $taskItem->getAttributes();

        DB::transaction(function () use ($request, $taskItem, $user, $original): void {
            $payload = $request->only(['title', 'description', 'priority', 'due_date', 'is_completed']);

            if (array_key_exists('is_completed', $payload)) {
                $taskItem->completed_at = $payload['is_completed'] ? Carbon::now() : null;
            }

            $taskItem->fill($payload)->save();

            $this->activity->recordTaskUpdate($user, $taskItem, $original, $payload);
        });

        $changed = array_keys(array_diff_assoc($taskItem->getAttributes(), $original));

        TaskItemUpdated::dispatch($taskItem->refresh()->load('column'), $changed);

        $this->notifyAssigneesOfUpdate($request->user(), $taskItem, $original, $changed);

        return response()->json([
            'task' => new TaskItemResource($taskItem),
        ]);
    }

    public function destroy(Request $request, TaskItem $taskItem): JsonResponse
    {
        $board = $taskItem->column->board;
        $this->authorize('update', $board);

        DB::transaction(function () use ($taskItem, $request): void {
            $this->activity->record($request->user(), $taskItem, ActivityAction::Updated, meta: ['deleted' => true]);
            $taskItem->delete();
        });

        TaskItemDeleted::dispatch($board->id, $taskItem->id);

        return response()->json(status: 204);
    }

    public function archive(Request $request, TaskItem $taskItem): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $user = $request->user();
        $wasArchived = (bool) $taskItem->is_archived;

        DB::transaction(function () use ($taskItem, $user): void {
            $willArchive = ! $taskItem->is_archived;
            $taskItem->update([
                'is_archived' => $willArchive,
                'archived_at' => $willArchive ? Carbon::now() : null,
            ]);
            $this->activity->record(
                $user,
                $taskItem,
                $taskItem->is_archived ? ActivityAction::Archived : ActivityAction::Unarchived,
            );
        });

        $this->notifyAssigneesOfUpdate(
            $user,
            $taskItem->refresh(),
            ['is_archived' => $wasArchived],
            ['is_archived'],
        );

        return response()->json([
            'task' => new TaskItemResource($taskItem),
        ]);
    }

    public function move(MoveTaskItemRequest $request, TaskItem $taskItem): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $fromColumnId = $taskItem->column_id;
        $fromPosition = (float) $taskItem->position;
        $toColumnId = (int) $request->input('column_id');
        $toPosition = (float) $request->input('position');
        $user = $request->user();

        // Validate target column lives on the same board.
        $targetColumn = TaskColumn::query()
            ->where('id', $toColumnId)
            ->where('board_id', $taskItem->column->board_id)
            ->firstOrFail();

        DB::transaction(function () use ($taskItem, $targetColumn, $toPosition, $user, $fromColumnId, $toColumnId): void {
            $taskItem->update([
                'column_id' => $targetColumn->id,
                'position' => $toPosition,
            ]);

            $this->activity->record(
                $user,
                $taskItem,
                ActivityAction::Moved,
                meta: ['from_column_id' => $fromColumnId, 'to_column_id' => $toColumnId],
            );
        });

        TaskItemMoved::dispatch($taskItem->fresh()->load('column'), $fromColumnId, $toColumnId, $fromPosition, $toPosition);

        // Only fire the notification when the task actually crossed
        // columns. Reordering within the same column is noise — the
        // spec defines column_id change as the trigger for `moved`.
        if ($fromColumnId !== $toColumnId) {
            $this->notifyAssigneesOfUpdate(
                $user,
                $taskItem->refresh(),
                ['column_id' => $fromColumnId],
                ['column_id'],
            );
        }

        return response()->json([
            'task' => new TaskItemResource($taskItem),
        ]);
    }

    /**
     * Fan out `task_updated` notifications to every assignee except the
     * actor. Intersects the requested $changed fields with the
     * notifiable set so edits to purely cosmetic fields (description)
     * stay silent. All templating lives here so the trigger sites
     * read like one-liners.
     *
     * @param  array<string, mixed>  $originalAttributes  Pre-mutation getAttributes() snapshot
     * @param  array<int, string>  $changedFields         Field names that were actually modified
     */
    private function notifyAssigneesOfUpdate(
        ?\App\Models\User $actor,
        TaskItem $task,
        array $originalAttributes,
        array $changedFields,
    ): void {
        $notifiable = array_values(array_intersect($changedFields, self::NOTIFIABLE_UPDATE_FIELDS));

        // Column moves go through the dedicated branch in move();
        // preserve that ordering here so a single update() that both
        // renames and moves still emits the notification.
        if (in_array('column_id', $changedFields, true)) {
            $notifiable[] = 'column_id';
        }

        if ($notifiable === []) {
            return;
        }

        $assigneeIds = $task->assignees()->pluck('users.id')->map(static fn ($id): int => (int) $id)->all();
        if ($assigneeIds === []) {
            return;
        }

        [$project, $workspace] = $this->notifications->projectContextFor($task);
        $body = $this->summariseChanges($task, $originalAttributes, $notifiable);

        $this->notifications->createMany(
            userIds: $assigneeIds,
            type: NotificationType::TaskUpdated,
            title: ($actor?->name ?? 'Someone').' updated "'.$task->title.'"',
            body: $body,
            actor: $actor,
            workspace: $workspace,
            project: $project,
            resourceType: 'task',
            resourceId: $task->id,
            metadata: [
                'board_id' => $task->column->board_id,
                'changes' => $notifiable,
            ],
        );
    }

    /**
     * Human-readable body for the `task_updated` notification. The spec
     * example ("Priority changed to high, moved to In Progress") shows
     * the expected shape — per-field phrasing stitched by commas.
     *
     * @param  array<string, mixed>  $original
     * @param  array<int, string>  $fields
     */
    private function summariseChanges(TaskItem $task, array $original, array $fields): string
    {
        $parts = [];

        foreach ($fields as $field) {
            $parts[] = match ($field) {
                'title' => 'renamed to "'.$task->title.'"',
                'priority' => 'priority changed to '.($task->priority?->value ?? 'unset'),
                'due_date' => $task->due_date !== null
                    ? 'due date set to '.$task->due_date->toDateString()
                    : 'due date cleared',
                'is_completed' => $task->is_completed ? 'marked complete' : 'reopened',
                'is_archived' => $task->is_archived ? 'archived' : 'restored',
                'column_id' => 'moved to '.(TaskColumn::find($task->column_id)?->name ?? 'another column'),
                default => $field.' changed',
            };
        }

        return ucfirst(implode(', ', $parts));
    }

    private function nextPosition(TaskColumn $column): float
    {
        $max = (float) TaskItem::query()->where('column_id', $column->id)->max('position');

        return $max > 0 ? $max + 10000 : 10000;
    }
}
