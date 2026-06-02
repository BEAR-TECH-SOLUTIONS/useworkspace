<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Enums\BoardTemplate;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ListArchivedTasksRequest;
use App\Http\Requests\Tasks\StoreTaskBoardRequest;
use App\Http\Requests\Tasks\UpdateTaskBoardRequest;
use App\Http\Resources\Tasks\TaskBoardResource;
use App\Http\Resources\Tasks\TaskItemResource;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Models\Tasks\TaskItem;
use App\Services\Activity\ActivityService;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class TaskBoardController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly ActivityService $activity,
    ) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        // Pattern B users (only child-level grants) can reach this list;
        // visibleScope() narrows the result to what they can actually see.
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        $boards = $this->perms
            ->visibleScope($request->user(), ResourceType::Board, $project)
            ->orderBy('created_at')
            ->get();

        return TaskBoardResource::collection($boards);
    }

    public function store(StoreTaskBoardRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $user = $request->user();
        $template = BoardTemplate::from(
            $request->input('template', BoardTemplate::Simple->value),
        );

        $board = DB::transaction(function () use ($request, $project, $user, $template): TaskBoard {
            $board = TaskBoard::create([
                'project_id' => $project->id,
                'name' => $request->string('name')->toString(),
                'description' => $request->input('description'),
                'is_default' => false,
                'created_by' => $user->id,
            ]);

            // Materialise the template's column list. Positions are spaced
            // by 10_000 so drag-reordering has room to insert new columns
            // without cascading updates.
            foreach ($template->columns() as $index => $name) {
                TaskColumn::create([
                    'board_id' => $board->id,
                    'name' => $name,
                    'position' => ($index + 1) * 10000.0,
                ]);
            }

            $this->activity->record($user, $board, ActivityAction::Created, meta: [
                'template' => $template->value,
            ]);

            return $board;
        });

        return response()->json([
            'board' => new TaskBoardResource($board->load('columns')),
        ], 201);
    }

    public function show(TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);

        // Eager-load columns with their items so the client can render the
        // kanban from a single request. Items are ordered by position ASC to
        // match the order they should appear inside each column. Each item
        // also hydrates its assignees, checklists, labels, and comments_count
        // so the desktop client can render cards and the task detail sheet
        // without a second round-trip.
        $taskBoard->load([
            'columns' => function ($query): void {
                $query->orderBy('position');
            },
            'columns.items' => function ($query): void {
                $query->where('is_archived', false)
                    ->orderBy('position')
                    ->withCount(['comments', 'resourceLinks']);
            },
            'columns.items.assignees',
            'columns.items.checklists' => function ($query): void {
                $query->orderBy('position');
            },
            'columns.items.labels',
        ]);

        return response()->json([
            'board' => new TaskBoardResource($taskBoard),
        ]);
    }

    public function update(UpdateTaskBoardRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);

        $user = $request->user();
        $original = $taskBoard->getAttributes();

        DB::transaction(function () use ($request, $taskBoard, $user, $original): void {
            $taskBoard->fill($request->only(['name', 'description']))->save();

            foreach (['name', 'description'] as $field) {
                if (! $taskBoard->wasChanged($field)) {
                    continue;
                }

                $this->activity->record(
                    $user,
                    $taskBoard,
                    ActivityAction::Updated,
                    field: $field,
                    old: $original[$field] ?? null,
                    new: $taskBoard->getAttribute($field),
                );
            }
        });

        return response()->json([
            'board' => new TaskBoardResource($taskBoard->refresh()),
        ]);
    }

    public function archive(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('archive', $taskBoard);

        // Audit M8: previously this method only wrote an activity row
        // and returned 200, leaving the board visible to everyone in
        // the sidebar. Refuse to archive the project default — every
        // project must have one — and otherwise flip is_archived in
        // the same transaction the activity log writes in.
        if ($taskBoard->is_default) {
            return response()->json([
                'message' => "The project's default board can't be archived.",
                'code' => 'cannot_archive_default_board',
            ], 422);
        }

        if (! $taskBoard->is_archived) {
            $taskBoard->forceFill([
                'is_archived' => true,
                'archived_at' => now(),
            ])->save();
        }

        $this->activity->record($request->user(), $taskBoard, ActivityAction::Archived);

        return response()->json([
            'board' => new TaskBoardResource($taskBoard->refresh()),
        ]);
    }

    /**
     * Paginated list of archived tasks on a board. Kept separate from
     * GET /task-boards/{taskBoard} (which omits archived tasks from the
     * column payload by design) so the kanban stays snappy while users
     * can still browse and restore archived work.
     *
     * Sorted by archived_at DESC (most recently archived first) with
     * id DESC as a stable tiebreaker for rows archived in the same tick.
     * Cursor is the id of the last item on the previous page; we walk
     * backward by id because id is strictly increasing and matches the
     * archived_at order closely enough that a single indexed `id <` on
     * the partial (column_id, archived_at DESC) index is sufficient.
     */
    public function archivedTasks(ListArchivedTasksRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);

        $limit = (int) $request->input('limit', 50);
        $cursor = $request->filled('cursor') ? (int) $request->input('cursor') : null;

        $query = TaskItem::query()
            ->whereIn('column_id', TaskColumn::query()->where('board_id', $taskBoard->id)->select('id'))
            ->where('is_archived', true)
            ->with(['labels', 'assignees', 'checklists' => fn ($q) => $q->orderBy('position')])
            ->withCount(['comments', 'resourceLinks'])
            ->orderByDesc('archived_at')
            ->orderByDesc('id');

        if ($cursor !== null) {
            $query->where('id', '<', $cursor);
        }

        $items = $query->limit($limit + 1)->get();

        $hasMore = $items->count() > $limit;
        $page = $hasMore ? $items->slice(0, $limit) : $items;
        $nextCursor = $hasMore ? (int) $page->last()->id : null;

        return response()->json([
            'data' => TaskItemResource::collection($page->values()),
            'next_cursor' => $nextCursor,
        ]);
    }

    public function destroy(TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('delete', $taskBoard);

        if ($taskBoard->is_default) {
            return response()->json(['message' => 'The default board cannot be deleted.'], 422);
        }

        $taskBoard->delete();

        return response()->json(status: 204);
    }
}
