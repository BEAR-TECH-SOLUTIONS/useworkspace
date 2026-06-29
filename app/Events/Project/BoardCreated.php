<?php

namespace App\Events\Project;

use App\Http\Resources\Tasks\TaskBoardResource;
use App\Models\Tasks\TaskBoard;

class BoardCreated extends ProjectChannelEvent
{
    public function __construct(
        public readonly TaskBoard $board,
        ?string $socketId = null,
    ) {
        parent::__construct($board->project_id, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'board.created';
    }

    protected function payload(): array
    {
        return ['board' => (new TaskBoardResource($this->board))->resolve()];
    }
}
