<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use App\Http\Resources\Tasks\TaskActivityResource;
use App\Models\Tasks\TaskActivity;
use Illuminate\Broadcasting\PrivateChannel;

class TaskActivityRecorded extends BroadcastEvent
{
    public function __construct(public readonly TaskActivity $activity) {}

    public function broadcastOn(): array
    {
        if ($this->activity->board_id === null) {
            return [];
        }

        return [new PrivateChannel('board.'.$this->activity->board_id)];
    }

    public function broadcastAs(): string
    {
        return 'activity.recorded';
    }

    protected function payload(): array
    {
        return ['activity' => (new TaskActivityResource($this->activity->loadMissing('user')))->resolve()];
    }
}
