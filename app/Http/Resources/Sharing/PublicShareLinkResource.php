<?php

namespace App\Http\Resources\Sharing;

use App\Models\Vault\ShareLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-facing projection of a ShareLink. The recipient sees only the
 * minimum needed to render an unlock screen — never the creator's
 * email or id (display name only), never the token, never any
 * cryptographic material on its own.
 *
 * @mixin ShareLink
 */
class PublicShareLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('creator:id,name');

        return [
            'resource_type' => $this->resource_type,
            'name' => $this->name,
            'created_at' => $this->created_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'views_remaining' => $this->viewsRemaining(),
            'created_by_name' => $this->creator?->name,
        ];
    }
}
