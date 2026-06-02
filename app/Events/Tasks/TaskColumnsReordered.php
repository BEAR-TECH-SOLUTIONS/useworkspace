<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

class TaskColumnsReordered extends BroadcastEvent
{
    /**
     * @param  array<int, array{id: int, position: float}>  $positions
     */
    public function __construct(
        public readonly int $boardId,
        public readonly array $positions,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'columns.reordered';
    }

    protected function payload(): array
    {
        return ['positions' => $this->positions];
    }
}
