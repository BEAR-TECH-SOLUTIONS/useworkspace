<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

class TaskChecklistItemToggled extends BroadcastEvent
{
    public function __construct(
        public readonly int $boardId,
        public readonly int $taskId,
        public readonly int $checklistId,
        public readonly bool $isChecked,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'task.checklistToggled';
    }

    protected function payload(): array
    {
        return [
            'taskId' => $this->taskId,
            'checklistId' => $this->checklistId,
            'isChecked' => $this->isChecked,
        ];
    }
}
