<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SetupMasterPasswordRequest extends FormRequest
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
            'master_password_salt' => ['required', 'string', 'max:512'],
            'master_password_verifier' => ['required', 'string', 'max:512'],
            'public_key' => ['required', 'string'],
            'encrypted_private_key' => ['required', 'string'],
            'private_key_iv' => ['required', 'string', 'max:64'],
        ];
    }
}
