<?php

namespace App\Http\Requests\Sharing;

use App\Rules\Sharing\Base64BytesLength;
use Illuminate\Foundation\Http\FormRequest;

class UnlockShareLinkRequest extends FormRequest
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
            // Raw token from the URL fragment / path; server hashes for lookup.
            'token' => ['required', 'string', 'min:1', 'max:256'],

            // Branch on auth_scheme; controller picks the right one.
            'password' => ['nullable', 'string', 'max:256'],
            'auth_proof' => ['nullable', 'string', new Base64BytesLength(32)],
        ];
    }
}
