<?php

namespace App\Http\Requests\Tasks;

use Illuminate\Foundation\Http\FormRequest;

class MoveTaskItemRequest extends FormRequest
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
            'column_id' => ['required', 'integer', 'exists:task_columns,id'],
            'position' => ['required', 'numeric'],
        ];
    }
}
