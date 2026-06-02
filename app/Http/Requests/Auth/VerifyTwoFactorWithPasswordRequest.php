<?php

namespace App\Http\Requests\Auth;

use App\Rules\Auth\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Re-verification of 2FA for sensitive actions: requires both a fresh
 * TOTP code AND the caller's current account password (audit H8). The
 * password proof guarantees that a stolen bearer token alone — even
 * with a captured TOTP code from a keyboard logger — is insufficient
 * to flip the 10-minute "2fa_verified" cache flag that gates
 * rotate-key, delete-project, etc.
 */
class VerifyTwoFactorWithPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:6'],
            // Hashes the supplied value against the authenticated user's
            // password_hash via Hash::check. No timing-side-channel
            // concern here because the route already requires auth.
            'current_password' => ['required', 'string', new CurrentPassword($this->user())],
        ];
    }
}
