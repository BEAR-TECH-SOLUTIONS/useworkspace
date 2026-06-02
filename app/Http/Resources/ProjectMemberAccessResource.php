<?php

namespace App\Http\Resources;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unified "what does this user have access to inside this project" shape,
 * returned by PUT /projects/{project}/members/{user}/access. Lets the
 * client setState one row in place — no /members + /me/access refetch.
 */
class ProjectMemberAccessResource extends JsonResource
{
    public function __construct(
        private readonly User $user,
        private readonly Project $project,
    ) {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rows = ResourcePermission::query()
            ->where('user_id', $this->user->id)
            ->where('project_id', $this->project->id)
            ->get();

        $projectRole = null;
        $grants = [];

        foreach ($rows as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type
                : ResourceType::from((string) $row->resource_type);
            $role = $row->role instanceof MemberRole
                ? $row->role->value
                : (string) $row->role;

            if ($type === ResourceType::Project) {
                $projectRole = $role;

                continue;
            }

            $grants[] = [
                'type' => $type->value,
                'id' => (int) $row->resource_id,
                'role' => $role,
            ];
        }

        return [
            'user_id' => $this->user->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'public_key' => $this->user->public_key,
            ],
            'project_role' => $projectRole,
            'resource_grants' => $grants,
        ];
    }
}
