<?php

namespace App\Http\Requests\Auth;

use App\Rules\Auth\CurrentPassword;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Disabling 2FA must require the account password (audit H8) on top
 * of the Require2FA middleware that already gates this endpoint. A
 * stolen bearer + a fresh 2fa_verified cache entry should NOT be
 * enough to remove the second factor entirely.
 */
class DisableTwoFactorRequest extends FormRequest
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
            'current_password' => ['required', 'string', new CurrentPassword($this->user())],
        ];
    }
}
