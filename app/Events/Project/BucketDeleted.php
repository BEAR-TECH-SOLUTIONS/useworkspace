<?php

namespace App\Events\Project;

class BucketDeleted extends ProjectChannelEvent
{
    public function __construct(
        int $projectId,
        public readonly int $bucketId,
        ?string $socketId = null,
    ) {
        parent::__construct($projectId, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'bucket.deleted';
    }

    protected function payload(): array
    {
        return ['id' => $this->bucketId];
    }
}
