<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

class TaskItemDeleted extends BroadcastEvent
{
    public function __construct(
        public readonly int $boardId,
        public readonly int $taskId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'task.deleted';
    }

    protected function payload(): array
    {
        return ['taskId' => $this->taskId];
    }
}
