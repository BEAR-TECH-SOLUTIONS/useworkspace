<?php

namespace App\Http\Resources\Sharing;

use App\Models\Vault\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-side projection of a ShareLink. Never exposes snapshot_payload,
 * password_hash, auth_proof_hash, or token_hash.
 *
 * @mixin ShareLink
 */
class ShareLinkSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'resource_type' => $this->resource_type,
            'resource_id' => (int) $this->resource_id,
            'project_id' => $this->project_id !== null ? (int) $this->project_id : null,
            'name' => $this->name,
            'auth_scheme' => $this->authScheme(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'max_views' => $this->max_views !== null ? (int) $this->max_views : null,
            'view_count' => (int) $this->view_count,
            'views_remaining' => $this->viewsRemaining(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => (int) $this->created_by,
            'is_active' => $this->isActive(),
        ];
    }
}
