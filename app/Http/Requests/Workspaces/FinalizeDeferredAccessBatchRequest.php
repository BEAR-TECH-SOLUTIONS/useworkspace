<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeDeferredAccessBatchRequest extends FormRequest
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
            'grants' => ['required', 'array', 'min:1'],
            'grants.*.deferred_access_id' => ['required', 'integer', 'min:1'],
            'grants.*.vault_keys' => ['sometimes', 'array'],
            'grants.*.vault_keys.*.vault_id' => ['required_with:grants.*.vault_keys', 'integer', 'min:1'],
            'grants.*.vault_keys.*.encrypted_key' => ['required_with:grants.*.vault_keys', 'string', 'max:8192'],
            'grants.*.vault_keys.*.key_version' => ['required_with:grants.*.vault_keys', 'integer', 'min:1'],
        ];
    }
}
