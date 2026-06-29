<?php

namespace App\Events\Project;

class BoardDeleted extends ProjectChannelEvent
{
    public function __construct(
        int $projectId,
        public readonly int $boardId,
        ?string $socketId = null,
    ) {
        parent::__construct($projectId, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'board.deleted';
    }

    protected function payload(): array
    {
        return ['id' => $this->boardId];
    }
}
