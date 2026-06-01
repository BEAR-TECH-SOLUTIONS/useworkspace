<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Tasks\TaskActivityResource;
use App\Models\Tasks\TaskActivity;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskActivityController extends Controller
{
    public function forTask(TaskItem $taskItem): AnonymousResourceCollection
    {
        $this->authorize('view', $taskItem->column->board);

        $activities = TaskActivity::query()
            ->with('user')
            ->where('task_item_id', $taskItem->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return TaskActivityResource::collection($activities);
    }

    public function forBoard(Request $request, TaskBoard $taskBoard): AnonymousResourceCollection
    {
        $this->authorize('view', $taskBoard);

        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $activities = TaskActivity::query()
            ->with('user')
            ->where('board_id', $taskBoard->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return TaskActivityResource::collection($activities);
    }
}
