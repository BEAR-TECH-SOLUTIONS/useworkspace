<?php

namespace App\Events\Tasks;

use App\Events\BroadcastEvent;
use App\Http\Resources\Tasks\TaskCommentResource;
use App\Models\Tasks\TaskComment;
use Illuminate\Broadcasting\PrivateChannel;

class TaskCommentCreated extends BroadcastEvent
{
    public function __construct(
        public readonly int $boardId,
        public readonly TaskComment $comment,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('board.'.$this->boardId)];
    }

    public function broadcastAs(): string
    {
        return 'task.commented';
    }

    protected function payload(): array
    {
        return [
            'taskId' => $this->comment->task_item_id,
            'comment' => (new TaskCommentResource($this->comment->loadMissing('author')))->resolve(),
        ];
    }
}
