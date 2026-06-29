<?php

namespace App\Events\Project;

use App\Http\Resources\Docs\DocResource;
use App\Models\Docs\Doc;

class DocUpdated extends ProjectChannelEvent
{
    public function __construct(
        public readonly Doc $doc,
        ?string $socketId = null,
    ) {
        parent::__construct($doc->project_id, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'doc.updated';
    }

    protected function payload(): array
    {
        return ['doc' => (new DocResource($this->doc))->resolve()];
    }
}
