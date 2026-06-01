<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use App\Models\Tasks\TaskItem;
use Illuminate\Broadcasting\PrivateChannel;

class TaskItemMoved extends BroadcastEvent
{
    public function __construct(
        public readonly TaskItem $task,
        public readonly int $fromColumnId,
        public readonly int $toColumnId,
        public readonly float $fromPosition,
        public readonly float $toPosition,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->task->column->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'task.moved';
    }

    protected function payload(): array
    {
        return [
            'taskId' => $this->task->id,
            'fromColumnId' => $this->fromColumnId,
            'toColumnId' => $this->toColumnId,
            'fromPosition' => $this->fromPosition,
            'toPosition' => $this->toPosition,
        ];
    }
}
