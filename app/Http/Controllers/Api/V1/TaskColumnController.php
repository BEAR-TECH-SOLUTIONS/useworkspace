<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Events\Tasks\TaskColumnCreated;
use App\Events\Tasks\TaskColumnDeleted;
use App\Events\Tasks\TaskColumnsReordered;
use App\Events\Tasks\TaskColumnUpdated;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\ReorderTaskColumnsRequest;
use App\Http\Requests\Tasks\StoreTaskColumnRequest;
use App\Http\Requests\Tasks\UpdateTaskColumnRequest;
use App\Http\Resources\Tasks\TaskColumnResource;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskColumn;
use App\Services\Activity\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskColumnController extends Controller
{
    public function __construct(private readonly ActivityService $activity) {}

    public function store(StoreTaskColumnRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);

        $user = $request->user();

        $column = DB::transaction(function () use ($request, $taskBoard, $user): TaskColumn {
            $column = TaskColumn::create([
                'board_id' => $taskBoard->id,
                'name' => $request->string('name')->toString(),
                'color' => $request->input('color'),
                'position' => (float) $request->input('position', $this->nextPosition($taskBoard)),
                'wip_limit' => $request->input('wip_limit'),
            ]);

            $this->activity->record(
                $user,
                $taskBoard,
                ActivityAction::ColumnCreated,
                meta: ['column_id' => $column->id, 'name' => $column->name],
            );

            return $column;
        });

        TaskColumnCreated::dispatch($column);

        return response()->json([
            'column' => new TaskColumnResource($column),
        ], 201);
    }

    public function update(UpdateTaskColumnRequest $request, TaskColumn $taskColumn): JsonResponse
    {
        $board = $taskColumn->board;
        $this->authorize('update', $board);

        $user = $request->user();
        $original = $taskColumn->getAttributes();

        DB::transaction(function () use ($request, $taskColumn, $board, $user, $original): void {
            $taskColumn->fill($request->only(['name', 'color', 'position', 'wip_limit']))->save();

            if ($taskColumn->wasChanged('name')) {
                $this->activity->record(
                    $user,
                    $board,
                    ActivityAction::ColumnRenamed,
                    meta: ['column_id' => $taskColumn->id],
                    field: 'name',
                    old: $original['name'] ?? null,
                    new: $taskColumn->name,
                );
            }
        });

        TaskColumnUpdated::dispatch($taskColumn->refresh());

        return response()->json([
            'column' => new TaskColumnResource($taskColumn),
        ]);
    }

    public function destroy(Request $request, TaskColumn $taskColumn): JsonResponse
    {
        $board = $taskColumn->board;
        $this->authorize('update', $board);

        $fallback = TaskColumn::query()
            ->where('board_id', $board->id)
            ->where('id', '!=', $taskColumn->id)
            ->orderBy('position')
            ->value('id');

        DB::transaction(function () use ($taskColumn, $request, $board): void {
            $this->activity->record(
                $request->user(),
                $board,
                ActivityAction::ColumnDeleted,
                meta: ['column_id' => $taskColumn->id, 'name' => $taskColumn->name],
            );

            $taskColumn->delete();
        });

        TaskColumnDeleted::dispatch($board->id, $taskColumn->id, $fallback);

        return response()->json(status: 204);
    }

    public function reorder(ReorderTaskColumnsRequest $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('update', $taskBoard);

        /** @var array<int, array{id: int, position: float}> $positions */
        $positions = $request->input('positions');

        DB::transaction(function () use ($positions, $taskBoard): void {
            foreach ($positions as $row) {
                TaskColumn::query()
                    ->where('id', $row['id'])
                    ->where('board_id', $taskBoard->id)
                    ->update(['position' => (float) $row['position']]);
            }
        });

        TaskColumnsReordered::dispatch($taskBoard->id, $positions);

        return response()->json([
            'columns' => TaskColumnResource::collection(
                TaskColumn::query()->where('board_id', $taskBoard->id)->orderBy('position')->get()
            ),
        ]);
    }

    private function nextPosition(TaskBoard $board): float
    {
        $max = (float) TaskColumn::query()->where('board_id', $board->id)->max('position');

        return $max > 0 ? $max + 10000 : 10000;
    }
}
