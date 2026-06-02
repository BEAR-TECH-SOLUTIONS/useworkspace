<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskResourceLinkKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskResourceLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller gates via PermissionService.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::enum(TaskResourceLinkKind::class)],
            'resource_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
