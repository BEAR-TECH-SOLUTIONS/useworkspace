<?php

namespace App\Events\Project;

class VaultDeleted extends ProjectChannelEvent
{
    public function __construct(
        int $projectId,
        public readonly int $vaultId,
        ?string $socketId = null,
    ) {
        parent::__construct($projectId, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'vault.deleted';
    }

    protected function payload(): array
    {
        return ['id' => $this->vaultId];
    }
}
