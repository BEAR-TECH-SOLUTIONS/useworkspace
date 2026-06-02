<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'title' => $this->title,
            'body' => $this->body,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'workspace_id' => $this->workspace_id,
            'workspace_name' => $this->workspace_name,
            'project_id' => $this->project_id,
            'project_name' => $this->project_name,
            'resource_type' => $this->resource_type,
            'resource_id' => $this->resource_id,
            'metadata' => $this->metadata ?? (object) [],
            'is_read' => (bool) $this->is_read,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
