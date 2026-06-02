<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Events\Tasks\TaskChecklistItemToggled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskChecklistRequest;
use App\Http\Requests\Tasks\UpdateTaskChecklistRequest;
use App\Http\Resources\Tasks\TaskChecklistResource;
use App\Models\Tasks\TaskChecklist;
use App\Models\Tasks\TaskItem;
use App\Services\Activity\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaskChecklistController extends Controller
{
    public function __construct(private readonly ActivityService $activity) {}

    public function store(StoreTaskChecklistRequest $request, TaskItem $taskItem): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $user = $request->user();

        $checklist = DB::transaction(function () use ($request, $taskItem, $user): TaskChecklist {
            $checklist = TaskChecklist::create([
                'task_item_id' => $taskItem->id,
                'text' => $request->string('text')->toString(),
                'is_checked' => (bool) $request->boolean('is_checked'),
                'position' => (float) $request->input('position', $this->nextPosition($taskItem)),
            ]);

            $this->activity->record(
                $user,
                $taskItem,
                ActivityAction::ChecklistAdded,
                meta: ['checklist_id' => $checklist->id, 'text' => $checklist->text],
            );

            return $checklist;
        });

        return response()->json([
            'checklist' => new TaskChecklistResource($checklist),
        ], 201);
    }

    public function update(UpdateTaskChecklistRequest $request, TaskChecklist $taskChecklist): JsonResponse
    {
        $task = $taskChecklist->task()->firstOrFail();
        $board = $task->column->board;
        $this->authorize('update', $board);

        $user = $request->user();
        $wasChecked = $taskChecklist->is_checked;

        DB::transaction(function () use ($request, $taskChecklist, $task, $user): void {
            $taskChecklist->fill($request->only(['text', 'is_checked', 'position']))->save();

            if ($taskChecklist->wasChanged('is_checked')) {
                $this->activity->record(
                    $user,
                    $task,
                    $taskChecklist->is_checked
                        ? ActivityAction::ChecklistChecked
                        : ActivityAction::ChecklistUnchecked,
                    meta: ['checklist_id' => $taskChecklist->id, 'text' => $taskChecklist->text],
                );
            }
        });

        if ($taskChecklist->is_checked !== $wasChecked) {
            TaskChecklistItemToggled::dispatch(
                $board->id,
                $task->id,
                $taskChecklist->id,
                $taskChecklist->is_checked,
            );
        }

        return response()->json([
            'checklist' => new TaskChecklistResource($taskChecklist->refresh()),
        ]);
    }

    public function destroy(Request $request, TaskChecklist $taskChecklist): JsonResponse
    {
        $task = $taskChecklist->task()->firstOrFail();
        $this->authorize('update', $task->column->board);

        $taskChecklist->delete();

        return response()->json(status: 204);
    }

    private function nextPosition(TaskItem $task): float
    {
        $max = (float) TaskChecklist::query()->where('task_item_id', $task->id)->max('position');

        return $max > 0 ? $max + 10000 : 10000;
    }
}
