<?php

namespace App\Http\Resources\Tasks;

use App\Models\Tasks\TaskItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskItem
 */
class TaskItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'column_id' => $this->column_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority?->value,
            'position' => (float) $this->position,
            'due_date' => $this->due_date?->toDateString(),
            'is_completed' => $this->is_completed,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Auxiliary relations — only included when the controller opts into
            // eager-loading, so the default TaskItem payload stays small.
            'labels' => TaskLabelResource::collection($this->whenLoaded('labels')),
            'assignees' => $this->whenLoaded('assignees', fn () => $this->assignees->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values()),
            'checklists' => TaskChecklistResource::collection($this->whenLoaded('checklists')),
            'comments_count' => $this->when(
                $this->checkCommentsCount(),
                fn () => (int) $this->comments_count,
            ),
            // Task Resource Attachments spec §5: always present so the
            // board view can render attachment badges without an N+1
            // link fetch. Controllers eager-load the count via
            // `withCount('resourceLinks')`; if they don't, fall back
            // to a scalar query.
            'resource_link_count' => (int) ($this->resource->resource_links_count
                ?? $this->resource->resourceLinks()->count()),
        ];
    }

    private function checkCommentsCount(): bool
    {
        return array_key_exists('comments_count', $this->resource->getAttributes())
            || isset($this->resource->comments_count);
    }
}
