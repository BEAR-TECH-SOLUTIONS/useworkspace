<?php

namespace App\Http\Requests\Projects;

use App\Enums\MemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorisation enforced in controller via $this->authorize().
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'exists:users,email'],
            'role' => ['required', Rule::enum(MemberRole::class)],
        ];
    }
}
