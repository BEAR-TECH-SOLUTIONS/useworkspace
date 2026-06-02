<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
        // No `unique:users,email` rule — audit H4 closes the
        // enumeration oracle by emitting the same generic message
        // for "invalid email" and "email already registered" from
        // withValidator(), instead of Laravel's default "has already
        // been taken." which uniquely identifies the collision.
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            // Don't double-report if the rfc/format check already
            // raised an error.
            if ($v->errors()->has('email')) {
                return;
            }

            $email = strtolower(trim((string) $this->input('email')));
            if ($email !== '' && User::query()->where('email', $email)->exists()) {
                // Generic message — identical phrasing the user would
                // see for any other email-rejection cause. Combined
                // with the per-IP register throttle this raises the
                // enumeration cost meaningfully.
                $v->errors()->add('email', __('Please use a valid, unused email address.'));
            }
        });
    }
}
