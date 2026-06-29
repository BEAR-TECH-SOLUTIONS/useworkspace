<?php

namespace App\Events\Project;

class DocDeleted extends ProjectChannelEvent
{
    public function __construct(
        int $projectId,
        public readonly int $docId,
        ?string $socketId = null,
    ) {
        parent::__construct($projectId, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'doc.deleted';
    }

    protected function payload(): array
    {
        return ['id' => $this->docId];
    }
}
