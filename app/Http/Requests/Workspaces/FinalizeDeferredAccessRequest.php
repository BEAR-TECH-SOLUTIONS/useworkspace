<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeDeferredAccessRequest extends FormRequest
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
            'vault_keys' => ['sometimes', 'array'],
            'vault_keys.*.vault_id' => ['required_with:vault_keys', 'integer', 'min:1'],
            'vault_keys.*.encrypted_key' => ['required_with:vault_keys', 'string', 'max:8192'],
            'vault_keys.*.key_version' => ['required_with:vault_keys', 'integer', 'min:1'],
        ];
    }
}
