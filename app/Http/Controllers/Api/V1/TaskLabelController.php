<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskLabelRequest;
use App\Http\Requests\Tasks\UpdateTaskLabelRequest;
use App\Http\Resources\Tasks\TaskLabelResource;
use App\Models\Project\Project;
use App\Models\Tasks\TaskItem;
use App\Models\Tasks\TaskLabel;
use App\Services\Activity\ActivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskLabelController extends Controller
{
    public function __construct(private readonly ActivityService $activity) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        $labels = TaskLabel::query()
            ->where('project_id', $project->id)
            ->orderBy('name')
            ->get();

        return TaskLabelResource::collection($labels);
    }

    public function store(StoreTaskLabelRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $label = TaskLabel::create([
            'project_id' => $project->id,
            'name' => $request->string('name')->toString(),
            'color' => $request->string('color')->toString(),
        ]);

        return response()->json([
            'label' => new TaskLabelResource($label),
        ], 201);
    }

    public function update(UpdateTaskLabelRequest $request, TaskLabel $taskLabel): JsonResponse
    {
        $project = $taskLabel->project;
        $this->authorize('update', $project);

        $taskLabel->fill($request->only(['name', 'color']))->save();

        return response()->json([
            'label' => new TaskLabelResource($taskLabel->refresh()),
        ]);
    }

    public function destroy(TaskLabel $taskLabel): JsonResponse
    {
        $project = $taskLabel->project;
        $this->authorize('update', $project);

        $taskLabel->delete();

        return response()->json(status: 204);
    }

    public function attach(Request $request, TaskItem $taskItem, TaskLabel $taskLabel): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        if ($taskLabel->project_id !== $taskItem->project_id) {
            return response()->json(['message' => 'Label does not belong to this project.'], 422);
        }

        $taskItem->labels()->syncWithoutDetaching([$taskLabel->id]);

        $this->activity->record(
            $request->user(),
            $taskItem,
            ActivityAction::Labeled,
            meta: ['label_id' => $taskLabel->id, 'name' => $taskLabel->name],
        );

        return response()->json(status: 204);
    }

    public function detach(Request $request, TaskItem $taskItem, TaskLabel $taskLabel): JsonResponse
    {
        $this->authorize('update', $taskItem->column->board);

        $taskItem->labels()->detach($taskLabel->id);

        $this->activity->record(
            $request->user(),
            $taskItem,
            ActivityAction::Unlabeled,
            meta: ['label_id' => $taskLabel->id, 'name' => $taskLabel->name],
        );

        return response()->json(status: 204);
    }
}
