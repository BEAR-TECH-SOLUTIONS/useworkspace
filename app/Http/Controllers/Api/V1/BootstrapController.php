<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\Docs\DocResource;
use App\Http\Resources\Expenses\ExpenseBucketResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\TaskBoardResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\Vault\VaultResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\Docs\Doc;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Permissions\ResourceKey;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/me/bootstrap — one-shot hydrate for the client's boot
 * sequence. Replaces the 5+ request fanout (/auth/me + /workspaces +
 * /me/access + /projects/{p}/resources × N) so the app becomes
 * interactive in a single round-trip.
 *
 * Individual endpoints still exist for post-mutation refreshes —
 * bootstrap is strictly boot-only and deliberately read-through.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $workspaces = Organisation::query()
            ->where(function ($q) use ($user): void {
                $q->where('owner_id', $user->id)
                    ->orWhereIn('id', function ($sub) use ($user): void {
                        $sub->select('organisation_id')
                            ->from('organisation_members')
                            ->where('user_id', $user->id);
                    });
            })
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get();

        $projects = Project::query()
            ->where(function ($q) use ($user): void {
                $q->where('owner_id', $user->id)
                    ->orWhereIn('id', function ($sub) use ($user): void {
                        $sub->select('project_id')
                            ->from('resource_permissions')
                            ->where('user_id', $user->id)
                            ->distinct();
                    });
            })
            ->orderBy('organisation_id')
            ->orderBy('name')
            ->get();

        if ($projects->isEmpty()) {
            return response()->json([
                'user' => new UserResource($user),
                'workspaces' => WorkspaceResource::collection($workspaces)->resolve($request),
                'projects' => [],
                'access' => $this->emptyAccess(),
            ])->header('Cache-Control', 'private, max-age=0');
        }

        $projectIds = $projects->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        // Bulk reads — 1 query per table regardless of project count.
        $grantsByProject = ResourcePermission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $projectIds)
            ->get()
            ->groupBy('project_id');

        $wrappedKeys = $this->loadWrappedKeys((int) $user->id, $projectIds);

        $boardsByProject = TaskBoard::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->get()
            ->groupBy('project_id');

        $vaultsByProject = Vault::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->get()
            ->groupBy('project_id');

        $bucketsByProject = ExpenseBucket::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('name')
            ->get()
            ->groupBy('project_id');

        $docsByProject = Doc::query()
            ->whereIn('project_id', $projectIds)
            ->orderBy('title')
            ->get()
            ->groupBy('project_id');

        $projectRoleByProjectId = [];
        $resourceRoleByKey = [];
        $wrappedKeyByResourceKey = [];
        $projectsOut = [];

        foreach ($projects as $project) {
            $projectGrants = $grantsByProject->get($project->id, collect());

            $projectRole = $this->resolveProjectRole((int) $user->id, $project, $projectGrants);
            if ($projectRole !== null) {
                $projectRoleByProjectId[(string) $project->id] = $projectRole;
            }

            $directByType = $this->bucketDirectGrants($projectGrants);

            $visibleBoards = $this->collectVisible(
                $boardsByProject->get($project->id, collect()),
                $directByType[ResourceType::Board->value],
                $projectRole,
                ResourceType::Board,
                $resourceRoleByKey,
            );

            $visibleVaults = $this->collectVisible(
                $vaultsByProject->get($project->id, collect()),
                $directByType[ResourceType::Vault->value],
                $projectRole,
                ResourceType::Vault,
                $resourceRoleByKey,
            );

            $visibleBuckets = $this->collectVisible(
                $bucketsByProject->get($project->id, collect()),
                $directByType[ResourceType::Bucket->value],
                $projectRole,
                ResourceType::Bucket,
                $resourceRoleByKey,
            );

            $visibleDocs = $this->collectVisible(
                $docsByProject->get($project->id, collect()),
                $directByType[ResourceType::Doc->value],
                $projectRole,
                ResourceType::Doc,
                $resourceRoleByKey,
            );

            // Stamp wrapped keys onto visible vaults + into the lookup
            // map. Unmigrated vaults and users without a wrapped-key
            // row naturally land as null on both sides.
            foreach ($visibleVaults as $vault) {
                $key = 'vault:'.$vault->id;
                $wrapped = $wrappedKeys[$key] ?? null;
                $vault->setAttribute(VaultResource::WRAPPED_KEY_ATTR, $wrapped);

                if ($wrapped !== null) {
                    $wrappedKeyByResourceKey[$key] = $wrapped;
                }
            }

            $projectsOut[] = array_merge(
                (new ProjectResource($project))->resolve($request),
                [
                    'boards' => TaskBoardResource::collection(collect($visibleBoards))->resolve($request),
                    'vaults' => VaultResource::collection(collect($visibleVaults))->resolve($request),
                    'buckets' => ExpenseBucketResource::collection(collect($visibleBuckets))->resolve($request),
                    'docs' => DocResource::previewCollection(collect($visibleDocs))->resolve($request),
                ],
            );
        }

        return response()->json([
            'user' => new UserResource($user),
            'workspaces' => WorkspaceResource::collection($workspaces)->resolve($request),
            'projects' => $projectsOut,
            'access' => [
                'project_role_by_project_id' => (object) $projectRoleByProjectId,
                'resource_role_by_key' => (object) $resourceRoleByKey,
                'wrapped_key_by_resource_key' => (object) $wrappedKeyByResourceKey,
            ],
        ])->header('Cache-Control', 'private, max-age=0');
    }

    /**
     * Pick the project-level role using the same cascade rule as
     * MeAccessController: workspace owner_id → explicit project
     * resource_permissions row → null (Pattern B).
     *
     * @param  \Illuminate\Support\Collection<int, ResourcePermission>  $projectGrants
     */
    private function resolveProjectRole(int $userId, Project $project, $projectGrants): ?string
    {
        if ((int) $project->owner_id === $userId) {
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
     * Bucket direct (child-level) grants by resource type so the
     * visibility loop can do an O(1) lookup per resource to apply the
     * "most specific wins" rule.
     *
     * @param  \Illuminate\Support\Collection<int, ResourcePermission>  $projectGrants
     * @return array<string, array<int, string>>
     */
    private function bucketDirectGrants($projectGrants): array
    {
        $out = [
            ResourceType::Vault->value => [],
            ResourceType::Board->value => [],
            ResourceType::Bucket->value => [],
            ResourceType::Doc->value => [],
        ];

        foreach ($projectGrants as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type->value
                : (string) $row->resource_type;

            if (! isset($out[$type])) {
                continue;
            }

            $out[$type][(int) $row->resource_id] = $row->role instanceof MemberRole
                ? $row->role->value
                : (string) $row->role;
        }

        return $out;
    }

    /**
     * Walk $items and return the models the user can see (cascade
     * role or direct role). Also populates $resourceRoleByKey so the
     * flattened access map lines up with the project payload.
     *
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $items
     * @param  array<int, string>  $directRolesById
     * @param  array<string, string>  $resourceRoleByKey
     * @return array<int, \Illuminate\Database\Eloquent\Model>
     */
    private function collectVisible(
        $items,
        array $directRolesById,
        ?string $cascadeRole,
        ResourceType $type,
        array &$resourceRoleByKey,
    ): array {
        $visible = [];

        foreach ($items as $item) {
            $id = (int) $item->getKey();
            $role = $directRolesById[$id] ?? $cascadeRole;

            if ($role === null) {
                continue;
            }

            $resourceRoleByKey[$type->value.':'.$id] = $role;
            $visible[] = $item;
        }

        return $visible;
    }

    /**
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

                // Keep the max-versioned row per (type, id) pair — the
                // list is already orderByDesc on key_version, so the
                // first entry wins.
                if (! isset($acc[$key])) {
                    $acc[$key] = [
                        'encrypted_key' => (string) $row->encrypted_key,
                        'key_version' => (int) $row->key_version,
                    ];
                }

                return $acc;
            }, []);
    }

    /**
     * @return array{project_role_by_project_id: object, resource_role_by_key: object, wrapped_key_by_resource_key: object}
     */
    private function emptyAccess(): array
    {
        return [
            'project_role_by_project_id' => (object) [],
            'resource_role_by_key' => (object) [],
            'wrapped_key_by_resource_key' => (object) [],
        ];
    }
}
