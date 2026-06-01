<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Models\Docs\Doc;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * GET /api/v1/me/access — returns the full access map for the current
 * user: every organisation they belong to, every project they can
 * reach, and every child resource (vault/board/bucket) visible to them
 * inside those projects. Vault rows include the caller's current
 * wrapped key + key_version so the client can seed
 * `accessStore.wrappedKeyByResourceKey` without hitting
 * `/projects/{p}/vaults` first.
 *
 * The resources list is expanded to include BOTH directly-granted
 * rows (Pattern B) AND cascaded ones (Pattern A — project owner or
 * project-level grant). The latter have no matching
 * `resource_permissions` row of their own, so the resolver has to
 * materialise them by walking the project's children and applying the
 * effective-role rule ("most specific wins").
 */
class MeAccessController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Every project the user can reach at all (owner, Pattern A,
        // or Pattern B). Trust the denormalised `project_id` column
        // on resource_permissions to avoid a 4-way union.
        $projects = Project::query()
            ->with('organisation')
            ->where(function ($q) use ($user): void {
                $q->where('owner_id', $user->id)
                    ->orWhereIn('id', function ($sub) use ($user): void {
                        $sub->select('project_id')
                            ->from('resource_permissions')
                            ->where('user_id', $user->id)
                            ->distinct();
                    });
            })
            ->get();

        if ($projects->isEmpty()) {
            return response()->json(['organisations' => []]);
        }

        $projectIds = $projects->pluck('id')->all();

        // Every grant this user holds inside those projects, grouped
        // by project. Project-level rows feed the cascade; child-level
        // rows override the cascade for their specific resource.
        $grants = ResourcePermission::query()
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $user->id)
            ->get()
            ->groupBy('project_id');

        // One shot each for the three child resource types. Archived
        // rows are intentionally included — the client uses the access
        // map to decide what's reachable, and an archived vault is
        // still readable by its members.
        $vaultsByProject = Vault::query()
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        $boardsByProject = TaskBoard::query()
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        $bucketsByProject = ExpenseBucket::query()
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        $docsByProject = Doc::query()
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        // Current-version wrapped keys for the caller, keyed by
        // "vault:{id}". Used to attach the `encrypted_key` +
        // `key_version` to vault entries below.
        $wrappedKeys = $this->loadWrappedKeys($user->id, $projectIds);

        $byOrg = [];

        foreach ($projects as $project) {
            $projectGrants = $grants->get($project->id, collect());

            // Effective project-level role: owner_id > project-level
            // resource_permissions row > null (Pattern B).
            $projectRole = $this->resolveProjectRole($user->id, $project, $projectGrants);

            // Bucket the direct child-level grants so the per-resource
            // loop can pick the "most specific wins" override in O(1).
            $directByType = [
                ResourceType::Vault->value => [],
                ResourceType::Board->value => [],
                ResourceType::Bucket->value => [],
                ResourceType::Doc->value => [],
            ];

            foreach ($projectGrants as $row) {
                $type = $row->resource_type instanceof ResourceType
                    ? $row->resource_type->value
                    : (string) $row->resource_type;

                if (! isset($directByType[$type])) {
                    continue;
                }

                $directByType[$type][(int) $row->resource_id] = $row->role instanceof MemberRole
                    ? $row->role->value
                    : (string) $row->role;
            }

            $resources = [];

            // Walk every child resource in the project. A row appears
            // in the access map if the user has either a cascaded role
            // (project-level) or a direct role; direct wins.
            $this->appendResources(
                $resources,
                ResourceType::Vault,
                $vaultsByProject->get($project->id, collect()),
                $directByType[ResourceType::Vault->value],
                $projectRole,
                $wrappedKeys,
            );

            $this->appendResources(
                $resources,
                ResourceType::Board,
                $boardsByProject->get($project->id, collect()),
                $directByType[ResourceType::Board->value],
                $projectRole,
                $wrappedKeys,
            );

            $this->appendResources(
                $resources,
                ResourceType::Bucket,
                $bucketsByProject->get($project->id, collect()),
                $directByType[ResourceType::Bucket->value],
                $projectRole,
                $wrappedKeys,
            );

            $this->appendResources(
                $resources,
                ResourceType::Doc,
                $docsByProject->get($project->id, collect()),
                $directByType[ResourceType::Doc->value],
                $projectRole,
                $wrappedKeys,
            );

            $org = $project->organisation;
            $orgId = $org?->id ?? 0;

            if (! isset($byOrg[$orgId])) {
                $byOrg[$orgId] = [
                    'id' => $orgId,
                    'name' => $org?->name,
                    'slug' => $org?->slug,
                    'projects' => [],
                ];
            }

            $byOrg[$orgId]['projects'][] = [
                'id' => $project->id,
                'name' => $project->name,
                'role' => $projectRole,
                'resources' => $resources,
            ];
        }

        return response()->json([
            'organisations' => array_values($byOrg),
        ]);
    }

    /**
     * @param  Collection<int, ResourcePermission>  $projectGrants
     */
    private function resolveProjectRole(int $userId, Project $project, Collection $projectGrants): ?string
    {
        if ($project->owner_id === $userId) {
            return MemberRole::Owner->value;
        }

        foreach ($projectGrants as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type->value
                : (string) $row->resource_type;

            if ($type !== ResourceType::Project->value) {
                continue;
            }

            return $row->role instanceof MemberRole
                ? $row->role->value
                : (string) $row->role;
        }

        return null;
    }

    /**
     * For every row in $items, push an access-map entry into $out
     * using the most-specific-wins rule: if the user has a direct
     * grant on this exact row, use it; otherwise fall back to the
     * cascaded project role; otherwise skip. Vault entries get the
     * wrapped-key attachment.
     *
     * @param  array<int, array<string, mixed>>  $out
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $items
     * @param  array<int, string>  $directRolesById
     * @param  array<string, array{encrypted_key: string, key_version: int}>  $wrappedKeys
     */
    private function appendResources(
        array &$out,
        ResourceType $type,
        Collection $items,
        array $directRolesById,
        ?string $cascadeRole,
        array $wrappedKeys,
    ): void {
        foreach ($items as $item) {
            $id = (int) $item->getKey();

            $role = $directRolesById[$id] ?? $cascadeRole;

            if ($role === null) {
                continue;
            }

            $entry = [
                'type' => $type->value,
                'id' => $id,
                'role' => $role,
            ];

            if ($type === ResourceType::Vault) {
                $wrapped = $wrappedKeys[$type->value.':'.$id] ?? null;
                $entry['encrypted_key'] = $wrapped['encrypted_key'] ?? null;
                $entry['key_version'] = $wrapped['key_version'] ?? null;
            }

            $out[] = $entry;
        }
    }

    /**
     * Load the current-version wrapped keys for the caller across
     * every project in $projectIds. Keyed by "vault:{id}" /
     * "project:{id}" to match the lookup below.
     *
     * @param  array<int, int>  $projectIds
     * @return array<string, array{encrypted_key: string, key_version: int}>
     */
    private function loadWrappedKeys(int $userId, array $projectIds): array
    {
        return ResourceKey::query()
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $userId)
            ->orderByDesc('key_version')
            ->get()
            ->reduce(function (array $acc, ResourceKey $row): array {
                $type = $row->resource_type instanceof ResourceType
                    ? $row->resource_type->value
                    : (string) $row->resource_type;

                $key = $type.':'.$row->resource_id;

                // orderByDesc → first row for this (type, id) pair
                // is the max version; subsequent rows are older.
                if (! isset($acc[$key])) {
                    $acc[$key] = [
                        'encrypted_key' => (string) $row->encrypted_key,
                        'key_version' => (int) $row->key_version,
                    ];
                }

                return $acc;
            }, []);
    }
}
