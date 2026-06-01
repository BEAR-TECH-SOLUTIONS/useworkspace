<?php

namespace App\Http\Requests\Vault;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class WrapVaultKeyRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'encrypted_key' => ['required', 'string', 'max:8192'],
            'key_version' => ['required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $value = $this->input('encrypted_key');
            if (is_string($value) && $value !== '' && base64_decode($value, true) === false) {
                $v->errors()->add('encrypted_key', 'encrypted_key must be valid base64.');
            }
        });
    }
}
