<?php

namespace App\Http\Requests\Projects;

use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Project::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organisation_id' => [
                'required',
                'integer',
                'exists:organisations,id',
            ],
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:50'],
            'modules_enabled' => ['nullable', 'array'],
            'modules_enabled.vault' => ['boolean'],
            'modules_enabled.tasks' => ['boolean'],
            'modules_enabled.expenses' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $organisationId = (int) $this->input('organisation_id');
            $organisation = Organisation::find($organisationId);

            if ($organisation === null) {
                return;
            }

            // Admins always can; members can iff the workspace's
            // `members_can_create_projects` toggle is on. Owner is
            // implicitly an admin via WorkspacePolicy::isAdmin.
            if (! \Illuminate\Support\Facades\Gate::forUser($this->user())
                ->allows('createProjects', $organisation)) {
                $validator->errors()->add(
                    'organisation_id',
                    'You cannot create projects in this organisation.',
                );
            }
        });
    }
}
