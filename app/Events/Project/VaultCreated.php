<?php

namespace App\Events\Project;

use App\Http\Resources\Vault\VaultResource;
use App\Models\Vault\Vault;

class VaultCreated extends ProjectChannelEvent
{
    public function __construct(
        public readonly Vault $vault,
        ?string $socketId = null,
    ) {
        parent::__construct($vault->project_id, $socketId);
    }

    public function broadcastAs(): string
    {
        return 'vault.created';
    }

    protected function payload(): array
    {
        // A single Reverb/Pusher broadcast delivers one identical payload to
        // every subscriber on the channel — it cannot be personalised per
        // recipient. `my_wrapped_key` is therefore emitted as null here (the
        // client tolerates null and seeds/keeps its own cached key). We null
        // it on a clone, not the original: the controller may have stashed
        // the actor's wrapped key on this model for the HTTP response, and
        // shipping one user's key to everyone would poison their caches.
        $vault = clone $this->vault;
        $vault->setAttribute(VaultResource::WRAPPED_KEY_ATTR, null);

        return ['vault' => (new VaultResource($vault))->resolve()];
    }
}
