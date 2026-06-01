<?php

namespace App\Http\Resources\Tasks;

use App\Models\Tasks\TaskChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskChecklist
 */
class TaskChecklistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_item_id' => $this->task_item_id,
            'text' => $this->text,
            'is_checked' => $this->is_checked,
            'position' => (float) $this->position,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
