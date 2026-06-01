<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Http\Resources\Docs\DocResource;
use App\Http\Resources\Expenses\ExpenseBucketResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\Tasks\TaskBoardResource;
use App\Http\Resources\Vault\VaultResource;
use App\Models\Docs\Doc;
use App\Models\Identity\Organisation;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskLabel;
use App\Models\Expenses\ExpenseBucket;
use App\Models\User;
use App\Models\Vault\Vault;
use App\Services\Permissions\PermissionService;
use App\Contracts\PlanLimits;
use App\Services\Project\ProjectBootstrapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectBootstrapper $projectBootstrapper,
        private readonly PermissionService $perms,
        private readonly PlanLimits $plans,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $projects = Project::query()
            ->where(function ($query) use ($userId): void {
                $query->where('owner_id', $userId)
                    ->orWhereIn('id', function ($sub) use ($userId): void {
                        $sub->select('project_id')
                            ->from('resource_permissions')
                            ->where('user_id', $userId)
                            ->distinct();
                    });
            })
            ->orderByDesc('created_at')
            ->get();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $user = $request->user();

        $workspace = Organisation::query()->findOrFail((int) $request->input('organisation_id'));
        $this->plans->assertCanCreateProject($workspace);

        $project = DB::transaction(function () use ($request, $user): Project {
            $project = Project::create([
                'organisation_id' => (int) $request->input('organisation_id'),
                'owner_id' => $user->id,
                'original_owner_id' => $user->id,
                'name' => $request->string('name')->toString(),
                'color' => $request->input('color', '#6366f1'),
                'icon' => $request->input('icon'),
                'modules_enabled' => $request->input('modules_enabled', [
                    'vault' => true,
                    'tasks' => true,
                    'expenses' => true,
                ]),
            ]);

            $this->projectBootstrapper->bootstrap($project, $user);

            return $project;
        });

        return response()->json([
            'project' => new ProjectResource($project),
        ], 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        // Pattern B users (only direct child-level grants) cannot pass
        // `view` on the project itself because there's no project-level
        // resource_permissions row for them — but they must still be
        // able to read the parent project's metadata so the client can
        // render the sidebar and navigate to the resource they were
        // granted. hasAnyGrantIn covers both patterns.
        abort_unless($this->perms->hasAnyGrantIn($request->user(), $project), 403);

        return response()->json([
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * Combined child-resource list for the project — boards, vaults,
     * and buckets in one shot. Replaces the 3-endpoint fanout the
     * client used to fire on every project switch so the sidebar
     * doesn't stagger.
     *
     * Reuses the exact per-type shapes the individual endpoints
     * return (TaskBoardResource / VaultResource / ExpenseBucketResource)
     * — the client's stores already expect those shapes, so there's
     * no transformation layer to adapt.
     *
     * Pattern B scoping applies per-type: a user with a direct board
     * grant but no vault grant sees their boards and an empty
     * `vaults` array, not 403. Archived vaults/buckets are excluded.
     */
    public function resources(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();

        // Same gate the per-type index endpoints use. Without a single
        // grant anywhere in the project, there's nothing to return and
        // leaking "this project exists" would let outsiders enumerate.
        abort_unless($this->perms->hasAnyGrantIn($user, $project), 403);

        $boards = $this->perms
            ->visibleScope($user, ResourceType::Board, $project)
            ->orderBy('name')
            ->get();

        $vaults = $this->perms
            ->visibleScope($user, ResourceType::Vault, $project)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $this->attachWrappedKeys($user, $vaults->all());

        $buckets = $this->perms
            ->visibleScope($user, ResourceType::Bucket, $project)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get();

        $docs = $this->perms
            ->visibleScope($user, ResourceType::Doc, $project)
            ->where('is_archived', false)
            ->orderBy('title')
            ->get();

        return response()->json([
            'boards' => TaskBoardResource::collection($boards)->resolve($request),
            'vaults' => VaultResource::collection($vaults)->resolve($request),
            'buckets' => ExpenseBucketResource::collection($buckets)->resolve($request),
            'docs' => DocResource::previewCollection($docs)->resolve($request),
        ]);
    }

    /**
     * Mirror of VaultController::attachWrappedKeys — stamps the
     * current-version wrapped key for $user onto each vault so
     * VaultResource can emit `my_wrapped_key`. Unmigrated vaults and
     * users without a key both show up as `null`.
     *
     * @param  array<int, Vault>  $vaults
     */
    private function attachWrappedKeys(User $user, array $vaults): void
    {
        if ($vaults === []) {
            return;
        }

        $keys = $this->perms->wrappedVaultKeysFor(
            $user,
            array_map(static fn (Vault $v): int => $v->id, $vaults),
        );

        foreach ($vaults as $vault) {
            $vault->setAttribute(
                VaultResource::WRAPPED_KEY_ATTR,
                $keys[$vault->id] ?? null,
            );
        }
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $project->fill($request->only([
            'name',
            'color',
            'icon',
            'modules_enabled',
            'auto_archive_completed',
            'archive_retention_days',
        ]))->save();

        return response()->json([
            'project' => new ProjectResource($project->refresh()),
        ]);
    }

    public function archive(Request $request, Project $project): JsonResponse
    {
        $this->authorize('archive', $project);

        $project->update(['is_archived' => true]);

        return response()->json([
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * Inverse of archive() — audit M9. Previously archive was a one-way
     * trip with no API path to restore, so a fat-fingered archive
     * required either a SQL hotfix or a brand-new project. Gated by
     * the same `archive` ability since restoring is logically the same
     * scope as deciding to put it away in the first place.
     */
    public function unarchive(Request $request, Project $project): JsonResponse
    {
        $this->authorize('archive', $project);

        $project->update(['is_archived' => false]);

        return response()->json([
            'project' => new ProjectResource($project),
        ]);
    }

    public function purgeContents(Request $request, Project $project): JsonResponse
    {
        $this->authorize('purgeContents', $project);

        DB::transaction(function () use ($project): void {
            // FK cascades on project_id handle the grandchildren (task
            // columns/items/activities, credentials, expenses, share
            // links, resource_keys, …). Deleting the top-level container
            // rows is enough to wipe each module.
            TaskBoard::query()->where('project_id', $project->id)->delete();
            TaskLabel::query()->where('project_id', $project->id)->delete();
            Vault::query()->where('project_id', $project->id)->delete();
            ExpenseBucket::query()->where('project_id', $project->id)->delete();
        });

        return response()->json(status: 204);
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        DB::transaction(function () use ($project): void {
            ResourcePermission::query()->where('project_id', $project->id)->delete();
            $project->delete();
        });

        return response()->json(status: 204);
    }
}
