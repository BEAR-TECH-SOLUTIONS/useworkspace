<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string'],
            // The `confirmed` rule pairs with `password_confirmation`
            // so callers can't skip the confirmation field.
            // `different` catches the "new == old" case so the
            // controller can keep its "current must differ" contract
            // without a second manual compare.
            // Audit L7: reuse the global password policy (12 chars +
            // mixed case + numbers + symbols + HIBP in prod) so a
            // weakened ChangePasswordRequest can't be the back-door
            // around the policy applied at registration.
            'password' => ['required', 'string', Password::defaults(), 'different:current_password', 'confirmed'],
        ];
    }
}
