<?php

namespace App\Events\Project;

use App\Http\Resources\Expenses\ExpenseBucketResource;
use App\Models\Expenses\ExpenseBucket;

class BucketCreated extends ProjectChannelEvent
{
    public function __construct(
        public readonly ExpenseBucket $bucket,
        ?string $socketId = null,
    ) {
        parent::__construct($bucket->project_id, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'bucket.created';
    }

    protected function payload(): array
    {
        return ['bucket' => (new ExpenseBucketResource($this->bucket))->resolve()];
    }
}
