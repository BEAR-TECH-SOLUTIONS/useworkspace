<?php

namespace App\Events;

use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Broadcasting\PrivateChannel;

class NotificationCreated extends BroadcastEvent
{
    public function __construct(public readonly Notification $notification) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('user.'.$this->notification->user_id)];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    protected function payload(): array
    {
        return [
            'notification' => (new NotificationResource($this->notification))->resolve(),
        ];
    }
}
