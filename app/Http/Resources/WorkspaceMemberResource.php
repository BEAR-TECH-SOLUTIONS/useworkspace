<?php

namespace App\Http\Resources;

use App\Models\Identity\OrganisationMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Directory row — what `GET /workspaces/{w}/members` returns. Critically
 * includes `public_key` so the project-invite dropdown can wrap vault
 * keys for the chosen user without a second /users/by-email round-trip.
 *
 * @mixin OrganisationMember
 */
class WorkspaceMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => (int) $this->user_id,
            'role' => $this->role?->value,
            'invited_by' => $this->invited_by !== null ? (int) $this->invited_by : null,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                // May be null while the invitee hasn't finished master-
                // password setup. The client uses this value directly
                // to wrap vault keys — no extra /public-key lookup.
                'public_key' => $this->user->public_key,
            ]),
        ];
    }
}
