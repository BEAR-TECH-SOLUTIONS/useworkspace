<?php

namespace App\Http\Resources;

use App\Enums\WorkspaceInvitationGrantMode;
use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkspaceInvitation
 */
class WorkspaceInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'workspace_id' => (int) $this->workspace_id,
            'inviter_id' => (int) $this->inviter_id,
            'invitee_id' => $this->invitee_id !== null ? (int) $this->invitee_id : null,
            'invitee_email' => $this->invitee_email,
            'role' => $this->role?->value,
            'status' => $this->status?->value,
            'projects' => $this->whenLoaded('projectGrants', fn () => $this->projectGrants->map(function ($grant) {
                $mode = $grant->mode instanceof WorkspaceInvitationGrantMode
                    ? $grant->mode->value
                    : (string) $grant->mode;

                $payload = [
                    'project_id' => (int) $grant->project_id,
                    'project_name' => $grant->project?->name,
                    'mode' => $mode,
                ];

                if ($mode === 'project') {
                    $vaultKeys = $grant->vaultKeys;
                    $payload['project_role'] = $grant->project_role?->value;
                    $payload['vault_keys_count'] = $vaultKeys->count();
                    // Pre-accept staleness signal — lets admin UIs
                    // warn "this invite has rotated keys, re-issue
                    // before the invitee tries".
                    $payload['has_stale_keys'] = $vaultKeys->contains(fn ($k) => $k->superseded_at !== null);
                } else {
                    $resources = $grant->resourceGrants;
                    $counts = ['vault' => 0, 'board' => 0, 'bucket' => 0];
                    foreach ($resources as $r) {
                        $type = $r->resource_type?->value ?? (string) $r->resource_type;
                        if (isset($counts[$type])) {
                            $counts[$type]++;
                        }
                    }
                    $payload['resources_count'] = $counts;
                    $payload['has_stale_keys'] = $resources->contains(fn ($r) => $r->superseded_at !== null);
                }

                return $payload;
            })->values()),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'inviter' => $this->whenLoaded('inviter', fn () => [
                'id' => $this->inviter->id,
                'name' => $this->inviter->name,
                'email' => $this->inviter->email,
            ]),
            'workspace' => $this->whenLoaded('workspace', fn () => [
                'id' => $this->workspace->id,
                'name' => $this->workspace->name,
                'slug' => $this->workspace->slug,
            ]),
        ];
    }
}
