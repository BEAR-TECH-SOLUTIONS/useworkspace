<?php

namespace App\Http\Requests\Projects;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorisation is enforced in the controller via $this->authorize().
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['sometimes', 'nullable', 'string', 'max:50'],
            'modules_enabled' => ['sometimes', 'array'],
            'modules_enabled.vault' => ['boolean'],
            'modules_enabled.tasks' => ['boolean'],
            'modules_enabled.expenses' => ['boolean'],
            'auto_archive_completed' => ['sometimes', 'boolean'],
            'archive_retention_days' => ['sometimes', 'integer', 'in:30,90,180'],
        ];
    }
}
