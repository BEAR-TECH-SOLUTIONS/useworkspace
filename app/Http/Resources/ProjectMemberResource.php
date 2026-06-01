<?php

namespace App\Http\Resources;

use App\Models\Permissions\ResourcePermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a project-level ResourcePermission row (resource_type='project').
 * "Member" of a project now means "user with a project-level grant in
 * resource_permissions" — there is no separate project_members table.
 *
 * @mixin ResourcePermission
 */
class ProjectMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'project_id' => (int) $this->project_id,
            'user_id' => (int) $this->user_id,
            'role' => $this->role?->value,
            'joined_at' => $this->created_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'public_key' => $this->user->public_key,
            ]),
        ];
    }
}