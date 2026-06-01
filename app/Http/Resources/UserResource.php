<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // `master_password_set` is the single source of truth the client
            // uses to decide whether to show the master-password setup screen.
            // The crypto bundle itself (salt/public_key/encrypted_private_key/
            // iv) only appears once it exists — before setup the bundle fields
            // are null and the client shouldn't rely on them.
            'master_password_set' => $this->resource->hasMasterPassword(),
            'master_password_salt' => $this->master_password_salt,
            'public_key' => $this->public_key,
            'encrypted_private_key' => $this->encrypted_private_key,
            'private_key_iv' => $this->private_key_iv,
            'two_factor_enabled' => (bool) $this->two_factor_enabled,
            // Cloud-admin bit (users.is_admin). The desktop client
            // reads this to decide whether to render the admin
            // surface (cloud-only). UserResource is only emitted for
            // the authenticated user themselves (login, /auth/me,
            // bootstrap, register, 2FA flows, master-password
            // setup) — never for arbitrary other users — so this
            // doesn't leak admin status across accounts.
            'is_admin' => (bool) ($this->is_admin ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
