<?php

namespace App\Events\Project;

use App\Events\BroadcastEvent;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Base for resource-lifecycle events on the project-wide channel
 * (`private-project.{projectId}`). The desktop client subscribes to this
 * channel (see `src/lib/realtimeProject.js`) and keeps its sidebar's
 * board / vault / bucket / doc lists live from these broadcasts.
 *
 * Subclasses provide `broadcastAs()` (the wire name, e.g. `board.created`)
 * and `payload()`. The originating client is excluded via the request's
 * `X-Socket-Id` (passed through to the framework's `socket` property), so
 * the actor never receives an echo of its own optimistic update.
 */
abstract class ProjectChannelEvent extends BroadcastEvent
{
    public function __construct(public readonly int $projectId, ?string $socketId = null)
    {
        // `socket` comes from InteractsWithSockets; the broadcasting layer
        // reads it and excludes that connection (broadcastToOthersExcept).
        $this->socket = $socketId;
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('project.'.$this->projectId)];
    }
}
