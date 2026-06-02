<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use App\Http\Resources\Tasks\TaskColumnResource;
use App\Models\Tasks\TaskColumn;
use Illuminate\Broadcasting\PrivateChannel;

class TaskColumnCreated extends BroadcastEvent
{
    public function __construct(public readonly TaskColumn $column) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->column->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'column.created';
    }

    protected function payload(): array
    {
        return ['column' => (new TaskColumnResource($this->column))->resolve()];
    }
}
