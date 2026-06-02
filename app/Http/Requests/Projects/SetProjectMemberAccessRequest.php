<?php

namespace App\Http\Requests\Projects;

use App\Enums\MemberRole;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Request payload for PUT /projects/{project}/members/{user}/access — the
 * unified member-mutation endpoint. Replaces the legacy POST/DELETE/PATCH
 * dance the client used to orchestrate across three routes.
 */
class SetProjectMemberAccessRequest extends FormRequest
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
            'mode' => ['required', Rule::in(['project', 'resources', 'none'])],
            'project_role' => ['required_if:mode,project', Rule::enum(MemberRole::class)],
            'resources' => ['required_if:mode,resources', 'array', 'min:1'],
            'resources.*.type' => ['required_with:resources', Rule::in(['vault', 'board', 'bucket', 'doc'])],
            'resources.*.id' => ['required_with:resources', 'integer'],
            'resources.*.role' => ['required_with:resources', Rule::enum(MemberRole::class)],
            'resources.*.encrypted_key' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->input('mode') !== 'resources') {
                return;
            }

            /** @var Project $project */
            $project = $this->route('project');
            /** @var array<int, array{type:string,id:int,role:string,encrypted_key?:?string}> $resources */
            $resources = (array) $this->input('resources', []);

            // Vault grants carry a wrapped key — the server stores it
            // verbatim into resource_keys but can't invent it.
            foreach ($resources as $i => $row) {
                if (($row['type'] ?? null) !== 'vault') {
                    continue;
                }
                $encrypted = $row['encrypted_key'] ?? null;
                if (! is_string($encrypted) || $encrypted === '') {
                    $v->errors()->add(
                        "resources.$i.encrypted_key",
                        'encrypted_key is required for vault grants.',
                    );
                }
            }

            // Every id in the payload must belong to the project on the
            // URL. A mismatched id would otherwise silently grant access
            // to a sibling project's resource.
            $vaultIds = [];
            $boardIds = [];
            $bucketIds = [];
            foreach ($resources as $row) {
                $type = $row['type'] ?? null;
                $id = (int) ($row['id'] ?? 0);
                match ($type) {
                    'vault' => $vaultIds[] = $id,
                    'board' => $boardIds[] = $id,
                    'bucket' => $bucketIds[] = $id,
                    default => null,
                };
            }

            if ($vaultIds !== []) {
                $found = Vault::query()
                    ->where('project_id', $project->id)
                    ->whereIn('id', $vaultIds)
                    ->pluck('id')
                    ->all();
                $missing = array_diff($vaultIds, $found);
                if ($missing !== []) {
                    $v->errors()->add('resources', 'Vault id(s) do not belong to this project: '.implode(',', $missing));
                }
            }
            if ($boardIds !== []) {
                $found = TaskBoard::query()
                    ->where('project_id', $project->id)
                    ->whereIn('id', $boardIds)
                    ->pluck('id')
                    ->all();
                $missing = array_diff($boardIds, $found);
                if ($missing !== []) {
                    $v->errors()->add('resources', 'Board id(s) do not belong to this project: '.implode(',', $missing));
                }
            }
            if ($bucketIds !== []) {
                $found = ExpenseBucket::query()
                    ->where('project_id', $project->id)
                    ->whereIn('id', $bucketIds)
                    ->pluck('id')
                    ->all();
                $missing = array_diff($bucketIds, $found);
                if ($missing !== []) {
                    $v->errors()->add('resources', 'Bucket id(s) do not belong to this project: '.implode(',', $missing));
                }
            }
        });
    }
}
