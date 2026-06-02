<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

class TaskColumnDeleted extends BroadcastEvent
{
    public function __construct(
        public readonly int $boardId,
        public readonly int $columnId,
        public readonly ?int $fallbackColumnId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'column.deleted';
    }

    protected function payload(): array
    {
        return [
            'columnId' => $this->columnId,
            'fallbackColumnId' => $this->fallbackColumnId,
        ];
    }
}
