<?php

namespace App\Events\Project;

use App\Http\Resources\Vault\VaultResource;
use App\Models\Vault\Vault;

class VaultUpdated extends ProjectChannelEvent
{
    public function __construct(
        public readonly Vault $vault,
        ?string $socketId = null,
    ) {
        parent::__construct($vault->project_id, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'vault.updated';
    }

    protected function payload(): array
    {
        // See VaultCreated::payload() — `my_wrapped_key` is null on the
        // shared project channel because one broadcast can't be serialised
        // per recipient. A metadata update (rename/color) never changes a
        // member's key, so the client keeps its cached key when it sees null.
        // Null on a clone so the controller's HTTP response keeps the actor's key.
        $vault = clone $this->vault;
        $vault->setAttribute(VaultResource::WRAPPED_KEY_ATTR, null);

        return ['vault' => (new VaultResource($vault))->resolve()];
    }
}
