<?php

namespace App\Http\Requests\Expenses;

use App\Enums\MemberRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseBucketMemberRequest extends FormRequest
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
            'email' => ['required', 'email:rfc', 'exists:users,email'],
            'role' => ['required', Rule::enum(MemberRole::class)],
        ];
    }
}