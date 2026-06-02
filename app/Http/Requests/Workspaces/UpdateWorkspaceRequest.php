<?php

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceRequest extends FormRequest
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
            // Rename, plus admin-controlled member-permission toggles.
            // Tier mutations still flow through the billing path
            // (WorkspaceBillingController + setTier) — not here.
            'name' => ['sometimes', 'string', 'max:80'],
            'members_can_create_projects' => ['sometimes', 'boolean'],
            'members_can_invite_members' => ['sometimes', 'boolean'],
        ];
    }
}
