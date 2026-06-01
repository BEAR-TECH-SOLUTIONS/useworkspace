<?php

namespace App\Http\Resources;

use App\Models\Project\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organisation_id' => $this->organisation_id,
            'owner_id' => $this->owner_id,
            'original_owner_id' => $this->original_owner_id,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_personal' => $this->is_personal,
            'is_archived' => $this->is_archived,
            'auto_archive_completed' => $this->auto_archive_completed,
            'archive_retention_days' => $this->archive_retention_days,
            'modules_enabled' => $this->modules_enabled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
