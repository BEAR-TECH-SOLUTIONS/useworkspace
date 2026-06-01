<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskItemRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'position' => ['nullable', 'numeric'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
