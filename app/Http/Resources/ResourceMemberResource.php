<?php

namespace App\Http\Resources;

use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generalised serializer for a `resource_permissions` row regardless of
 * whether it points at a vault, board, or bucket. ProjectMemberResource
 * stays tailored to the project-level shape the client already parses;
 * this one is used by the per-resource member endpoints (Pattern B).
 *
 * @mixin ResourcePermission
 */
class ResourceMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource_type instanceof ResourceType
            ? $this->resource_type->value
            : (string) $this->resource_type;

        return [
            'resource_type' => $type,
            'resource_id' => (int) $this->resource_id,
            'project_id' => (int) $this->project_id,
            'user_id' => (int) $this->user_id,
            'role' => $this->role?->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'public_key' => $this->user->public_key,
            ]),
        ];
    }
}