<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base class for every realtime event the API ships (CLAUDE.md §8.3).
 *
 * Subclasses provide:
 *   - broadcastOn(): the channels to publish on
 *   - broadcastAs(): the wire-protocol event name (e.g. `task.moved`)
 *   - payload(): the JSON payload sent to subscribers
 *
 * The actor's own socket is excluded automatically (broadcastToOthersExcept)
 * so optimistic UI updates don't visibly bounce back. Subclasses just emit;
 * the controller is expected to call `dispatch(...)->toOthers()` when it has
 * an `X-Socket-Id` to honour.
 */
abstract class BroadcastEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function broadcastQueue(): string
    {
        return 'reverb';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload();
    }

    /**
     * @return array<int, Channel>
     */
    abstract public function broadcastOn(): array;

    abstract public function broadcastAs(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(): array;
}
