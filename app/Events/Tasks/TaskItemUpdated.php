<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use App\Http\Resources\Tasks\TaskItemResource;
use App\Models\Tasks\TaskItem;
use Illuminate\Broadcasting\PrivateChannel;

class TaskItemUpdated extends BroadcastEvent
{
    /**
     * @param  array<int, string>  $changedFields
     */
    public function __construct(
        public readonly TaskItem $task,
        public readonly array $changedFields,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->task->column->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'task.updated';
    }

    protected function payload(): array
    {
        return [
            'task' => (new TaskItemResource($this->task))->resolve(),
            'changedFields' => array_values($this->changedFields),
        ];
    }
}
