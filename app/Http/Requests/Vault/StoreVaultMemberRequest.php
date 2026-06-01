<?php

namespace App\Http\Requests\Vault;

use App\Enums\MemberRole;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVaultMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller gates via $this->authorize('share', $vault).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'exists:users,email'],
            'role' => ['required', Rule::enum(MemberRole::class)],
            // Vault grants carry a crypto plane — the client must wrap the
            // current vault key under the invitee's RSA public key before
            // sending. The server only stores ciphertext.
            'encrypted_key' => ['required', 'string', 'max:8192'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $value = $this->input('encrypted_key');
            if (! is_string($value) || $value === '' || base64_decode($value, true) === false) {
                $v->errors()->add('encrypted_key', 'encrypted_key must be valid base64.');
            }
        });
    }
}