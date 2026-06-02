<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Structural validation for direct user provisioning. Domain checks
 * (email uniqueness, tier gating, seat cap, per-project authz) live
 * in WorkspaceProvisioningService + WorkspaceInvitationService so they
 * can run inside the same transaction and emit structured codes.
 *
 * The `projects` block mirrors CreateWorkspaceInvitationRequest so the
 * desktop client can share a single dialog for both flows.
 */
class ProvisionWorkspaceUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Controller gates via `manageMembers`.
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc'],
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['sometimes', Rule::enum(OrganisationRole::class)],
            'create_personal_workspace' => ['sometimes', 'boolean'],

            'projects' => ['sometimes', 'array'],
            'projects.*.project_id' => ['required_with:projects', 'integer', 'min:1'],
            'projects.*.mode' => ['required_with:projects', Rule::in(['project', 'resources'])],

            'projects.*.project_role' => ['required_if:projects.*.mode,project', 'nullable', Rule::enum(MemberRole::class)],
            'projects.*.vault_keys' => ['sometimes', 'array'],
            'projects.*.vault_keys.*.vault_id' => ['required_with:projects.*.vault_keys', 'integer', 'min:1'],
            'projects.*.vault_keys.*.encrypted_key' => ['required_with:projects.*.vault_keys', 'string', 'max:8192'],
            'projects.*.vault_keys.*.key_version' => ['required_with:projects.*.vault_keys', 'integer', 'min:1'],

            'projects.*.resources' => ['required_if:projects.*.mode,resources', 'array', 'min:1'],
            'projects.*.resources.*.type' => ['required_with:projects.*.resources', Rule::in(['vault', 'board', 'bucket', 'doc'])],
            'projects.*.resources.*.id' => ['required_with:projects.*.resources', 'integer', 'min:1'],
            'projects.*.resources.*.role' => ['required_with:projects.*.resources', Rule::enum(MemberRole::class)],
            'projects.*.resources.*.encrypted_key' => ['sometimes', 'nullable', 'string', 'max:8192'],
            'projects.*.resources.*.key_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    // No cross-field validator: in the deferred-provisioning model
    // vault resources no longer need `encrypted_key` / `key_version`
    // at request time — keys are wrapped by a project owner during
    // finalise, once the new user's public key exists. Structural
    // checks in rules() are sufficient.
}
