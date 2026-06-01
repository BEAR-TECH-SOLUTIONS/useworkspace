<?php

namespace App\Http\Resources\Tasks;

use App\Models\Tasks\TaskColumn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskColumn
 */
class TaskColumnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'color' => $this->color,
            'position' => (float) $this->position,
            'wip_limit' => $this->wip_limit,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Only included when the board-detail endpoint eager-loads items.
            // Everywhere else columns stay lean.
            'items' => TaskItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
