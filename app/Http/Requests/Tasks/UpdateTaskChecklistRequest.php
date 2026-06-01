<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskChecklistRequest extends FormRequest
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
            'text' => ['sometimes', 'string', 'max:500'],
            'is_checked' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'numeric'],
        ];
    }
}
