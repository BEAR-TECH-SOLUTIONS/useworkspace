<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganisationRole;
use App\Enums\PlanTier;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\ProvisionWorkspaceUserRequest;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Enums\ResourceType;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Contracts\PlanLimits;
use App\Services\Workspaces\WorkspaceProvisioningService;
use App\Models\Docs\Doc;
use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\Tasks\TaskBoard;
use App\Models\Vault\Vault;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Workspace CRUD. DB table stays `organisations`; API surface says
 * `workspaces` per the rename decision in the spec.
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $userId = $request->user()->id;

        $workspaces = Organisation::query()
            ->where(function ($q) use ($userId): void {
                $q->where('owner_id', $userId)
                    ->orWhereIn('id', function ($sub) use ($userId): void {
                        $sub->select('organisation_id')
                            ->from('organisation_members')
                            ->where('user_id', $userId);
                    });
            })
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function show(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('view', $workspace);

        return response()->json([
            'workspace' => new WorkspaceResource($workspace),
        ]);
    }

    public function store(StoreWorkspaceRequest $request): JsonResponse
    {
        $user = $request->user();
        $name = $request->string('name')->toString();

        // Audit M11: cap how many non-personal workspaces a single
        // user can own. Without this, an attacker who registers one
        // account can spin up unbounded workspaces and starve the
        // database / billing surface. The cap is generous (20 per
        // user) so it doesn't hit real users in practice. Personal
        // workspaces (auto-created at registration) don't count.
        $ownedCount = Organisation::query()
            ->where('owner_id', $user->id)
            ->where('is_personal', false)
            ->count();

        $cap = (int) config('teamcore.limits.max_workspaces_per_user', 20);
        if ($ownedCount >= $cap) {
            return response()->json([
                'message' => "You've reached the per-account workspace limit ({$cap}). Archive an existing workspace before creating a new one.",
                'code' => 'workspace_cap_reached',
            ], 422);
        }

        $workspace = DB::transaction(function () use ($user, $name): Organisation {
            $workspace = Organisation::create([
                'owner_id' => $user->id,
                'name' => $name,
                'slug' => Str::slug($name.'-'.Str::random(6)),
                'is_personal' => false,
                'tier' => PlanTier::Free->value,
                'seat_cap' => PlanTier::Free->defaultSeatCap(),
            ]);

            OrganisationMember::create([
                'organisation_id' => $workspace->id,
                'user_id' => $user->id,
                'role' => OrganisationRole::Admin->value,
                'invited_by' => null,
            ]);

            return $workspace;
        });

        return response()->json([
            'workspace' => new WorkspaceResource($workspace),
        ], 201);
    }

    /**
     * Admin-only picker feed for the member access dialog. Returns
     * every project in the workspace with its boards, vaults, and
     * expense buckets in four cheap queries (projects + one per
     * resource type). Regular members shouldn't be able to enumerate
     * resources they can't access — gated by `viewResourceTree`,
     * which is admin-only but (unlike `manageMembers`) does NOT
     * block personal workspaces. Personal workspaces can render
     * the picker too; there's just nothing actionable on the other
     * end, which is the provision/invite flow's problem to handle.
     *
     * Shape is deliberately minimal — id/name only on resources, plus
     * `migrated_at` on vaults so the invite flow can skip key wrapping
     * for legacy (unmigrated) vaults. No counts, no nested data.
     */
    public function resourceTree(Request $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('viewResourceTree', $workspace);

        $caller = $request->user();
        $isOwner = (int) $workspace->owner_id === (int) $caller->id;

        $allWorkspaceProjectIds = Project::query()
            ->where('organisation_id', $workspace->id)
            ->pluck('id')
            ->all();

        if ($allWorkspaceProjectIds === []) {
            return response()->json(['projects' => []]);
        }

        // Access scoping: the tree powers the provision / invite
        // picker, so non-owners must only see projects + resources
        // they themselves hold grants on. A workspace admin without
        // project grants shouldn't be able to enumerate (or grant
        // onward) resources they can't access.
        if ($isOwner) {
            $visibleProjectIds = $allWorkspaceProjectIds;
            $projectIdsWithProjectGrant = $allWorkspaceProjectIds;
            $directBoardIdsByProject = [];
            $directVaultIdsByProject = [];
            $directBucketIdsByProject = [];
            $directDocIdsByProject = [];
        } else {
            [
                $visibleProjectIds,
                $projectIdsWithProjectGrant,
                $directBoardIdsByProject,
                $directVaultIdsByProject,
                $directBucketIdsByProject,
                $directDocIdsByProject,
            ] = $this->resolveCallerScope($caller->id, $allWorkspaceProjectIds);
        }

        if ($visibleProjectIds === []) {
            return response()->json(['projects' => []]);
        }

        $projects = Project::query()
            ->whereIn('id', $visibleProjectIds)
            ->orderBy('name')
            ->get();

        // Fetch every resource that's EITHER in a project the caller
        // has cascading access to, OR one they have a direct grant on.
        // The OR is inclusive so a user with both a project-level
        // grant on X and a direct grant on one of X's vaults still
        // sees X's full resource list (cascade wins).
        $directBoardIds = array_values(array_unique(array_merge(...array_values($directBoardIdsByProject) ?: [[]])));
        $directVaultIds = array_values(array_unique(array_merge(...array_values($directVaultIdsByProject) ?: [[]])));
        $directBucketIds = array_values(array_unique(array_merge(...array_values($directBucketIdsByProject) ?: [[]])));
        $directDocIds = array_values(array_unique(array_merge(...array_values($directDocIdsByProject) ?: [[]])));

        $boards = TaskBoard::query()
            ->where(function ($q) use ($projectIdsWithProjectGrant, $directBoardIds): void {
                $q->whereIn('project_id', $projectIdsWithProjectGrant);
                if ($directBoardIds !== []) {
                    $q->orWhereIn('id', $directBoardIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'project_id', 'name'])
            ->groupBy('project_id');

        $vaults = Vault::query()
            ->where('is_archived', false)
            ->where(function ($q) use ($projectIdsWithProjectGrant, $directVaultIds): void {
                $q->whereIn('project_id', $projectIdsWithProjectGrant);
                if ($directVaultIds !== []) {
                    $q->orWhereIn('id', $directVaultIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'project_id', 'name', 'migrated_at'])
            ->groupBy('project_id');

        $buckets = ExpenseBucket::query()
            ->where('is_archived', false)
            ->where(function ($q) use ($projectIdsWithProjectGrant, $directBucketIds): void {
                $q->whereIn('project_id', $projectIdsWithProjectGrant);
                if ($directBucketIds !== []) {
                    $q->orWhereIn('id', $directBucketIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'project_id', 'name'])
            ->groupBy('project_id');

        $docs = Doc::query()
            ->where('is_archived', false)
            ->where(function ($q) use ($projectIdsWithProjectGrant, $directDocIds): void {
                $q->whereIn('project_id', $projectIdsWithProjectGrant);
                if ($directDocIds !== []) {
                    $q->orWhereIn('id', $directDocIds);
                }
            })
            ->orderBy('title')
            ->get(['id', 'project_id', 'title'])
            ->groupBy('project_id');

        $data = $projects->map(fn (Project $p): array => [
            'id' => $p->id,
            'name' => $p->name,
            'icon' => $p->icon,
            'color' => $p->color,
            'boards' => ($boards->get($p->id) ?? collect())->map(
                static fn (TaskBoard $b): array => ['id' => $b->id, 'name' => $b->name],
            )->values()->all(),
            'vaults' => ($vaults->get($p->id) ?? collect())->map(
                static fn (Vault $v): array => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'migrated_at' => $v->migrated_at?->toIso8601String(),
                ],
            )->values()->all(),
            'buckets' => ($buckets->get($p->id) ?? collect())->map(
                static fn (ExpenseBucket $b): array => ['id' => $b->id, 'name' => $b->name],
            )->values()->all(),
            // Docs in the picker use `name` (not `title`) to match the
            // shape of boards/vaults/buckets — the client renders a
            // single list with a uniform key.
            'docs' => ($docs->get($p->id) ?? collect())->map(
                static fn (Doc $d): array => ['id' => $d->id, 'name' => $d->title],
            )->values()->all(),
        ])->values()->all();

        return response()->json(['projects' => $data]);
    }

    /**
     * Build the non-owner access scope in a single resource_permissions
     * read. Returns:
     *   - visibleProjectIds: every project the caller can see anything in
     *   - projectIdsWithProjectGrant: projects the caller has cascading
     *     access to (project-level row) — full resource list
     *   - directBoardIdsByProject / directVaultIdsByProject /
     *     directBucketIdsByProject: narrow child grants keyed by
     *     project_id, surfaced only when the caller has no cascading
     *     grant on that project.
     *
     * @param  array<int, int>  $workspaceProjectIds
     * @return array{0: array<int,int>, 1: array<int,int>, 2: array<int, array<int,int>>, 3: array<int, array<int,int>>, 4: array<int, array<int,int>>}
     */
    private function resolveCallerScope(int $userId, array $workspaceProjectIds): array
    {
        $rows = ResourcePermission::query()
            ->where('user_id', $userId)
            ->whereIn('project_id', $workspaceProjectIds)
            ->get(['resource_type', 'resource_id', 'project_id']);

        $projectIdsWithProjectGrant = [];
        $directBoards = [];
        $directVaults = [];
        $directBuckets = [];
        $directDocs = [];

        foreach ($rows as $row) {
            $type = $row->resource_type instanceof ResourceType
                ? $row->resource_type
                : ResourceType::from((string) $row->resource_type);

            $projectId = (int) $row->project_id;
            $resourceId = (int) $row->resource_id;

            match ($type) {
                ResourceType::Project => $projectIdsWithProjectGrant[] = $projectId,
                ResourceType::Board => $directBoards[$projectId][] = $resourceId,
                ResourceType::Vault => $directVaults[$projectId][] = $resourceId,
                ResourceType::Bucket => $directBuckets[$projectId][] = $resourceId,
                ResourceType::Doc => $directDocs[$projectId][] = $resourceId,
            };
        }

        $projectIdsWithProjectGrant = array_values(array_unique($projectIdsWithProjectGrant));

        // "Visible" = project-level grants ∪ any child-level grants.
        // Even a single direct board grant means the project shows up
        // in the tree, but only its own resource appears — see
        // materialisation below.
        $visibleProjectIds = array_values(array_unique(array_merge(
            $projectIdsWithProjectGrant,
            array_keys($directBoards),
            array_keys($directVaults),
            array_keys($directBuckets),
            array_keys($directDocs),
        )));

        // Child grants inside a cascade-covered project are noise — the
        // cascade already grants everything. Drop them so the
        // materialisation logic doesn't double-count or accidentally
        // narrow the list.
        foreach ($projectIdsWithProjectGrant as $projectId) {
            unset(
                $directBoards[$projectId],
                $directVaults[$projectId],
                $directBuckets[$projectId],
                $directDocs[$projectId],
            );
        }

        return [
            $visibleProjectIds,
            $projectIdsWithProjectGrant,
            $directBoards,
            $directVaults,
            $directBuckets,
            $directDocs,
        ];
    }

    public function update(UpdateWorkspaceRequest $request, Organisation $workspace): JsonResponse
    {
        $this->authorize('update', $workspace);

        if ($request->has('name')) {
            $workspace->name = $request->string('name')->toString();
        }
        if ($request->has('members_can_create_projects')) {
            $workspace->members_can_create_projects = $request->boolean('members_can_create_projects');
        }
        if ($request->has('members_can_invite_members')) {
            $workspace->members_can_invite_members = $request->boolean('members_can_invite_members');
        }

        $workspace->save();

        return response()->json([
            'workspace' => new WorkspaceResource($workspace->refresh()),
        ]);
    }

    /**
     * Direct user provisioning — Team / Self-Hosted only.
     * Creates the account, adds workspace membership, and applies
     * project/resource access in one transaction. No invitation,
     * no accept step.
     *
     * Rejection codes:
     *   • generic 403 — caller is not a member of the workspace.
     *   • 403 feature_not_available — plan doesn't permit direct
     *     provisioning (currently Free / Entrepreneur).
     *   • 403 generic — caller is a workspace member but not an
     *     admin. Standard manageMembers policy denial.
     *   • 422 plan_limit_provision_users — plan permits direct
     *     provisioning by default but a per-workspace
     *     plan_limits override has it disabled.
     *
     * `is_personal` on the workspace is purely metadata (marks the
     * auto-bootstrapped workspace from register, exempt from the
     * M11 per-user workspace cap) and does NOT block provisioning.
     */
    public function provisionUser(
        ProvisionWorkspaceUserRequest $request,
        Organisation $workspace,
        WorkspaceProvisioningService $provisioner,
        PlanLimits $plans,
    ): JsonResponse {
        // Membership gate first — anyone who isn't even in the
        // workspace gets the generic 403 with no further detail.
        // This is the only place we leak nothing useful, and that's
        // intentional: it's identical to /workspaces/{w}/members.
        $this->authorize('view', $workspace);

        // Tier-feature gate next — before the admin check — so a
        // workspace-member-but-not-admin still sees the more useful
        // feature_not_available code on a plan that doesn't qualify.
        // Membership has already been established, so it's safe to
        // expose this much workspace state.
        if (! $provisioner->isAvailableFor($workspace)) {
            return response()->json([
                'message' => 'Direct user provisioning is not available on this workspace plan.',
                'code' => 'feature_not_available',
            ], 403);
        }

        // Admin gate. Generic 403 from the policy — the manageMembers
        // gate is the same one /workspaces/{w}/members uses.
        $this->authorize('manageMembers', $workspace);

        // PlanLimits raises 422 `plan_limit_provision_users` /
        // `plan_limit_members` for plan-cap overrides; this is the
        // billing-flavoured rejection that fires after the static
        // feature gate above (which covers the default-tier case).
        $plans->assertCanProvisionUser($workspace);

        $role = $request->filled('role')
            ? OrganisationRole::from($request->string('role')->toString())
            : OrganisationRole::Member;

        $result = $provisioner->provision(
            workspace: $workspace,
            admin: $request->user(),
            email: $request->string('email')->toString(),
            name: $request->string('name')->toString(),
            password: $request->string('password')->toString(),
            role: $role,
            projects: (array) $request->input('projects', []),
            createPersonalWorkspace: $request->boolean('create_personal_workspace', true),
        );

        return response()->json([
            'user' => new UserResource($result['user']),
            'membership' => [
                'user_id' => (int) $result['membership']->user_id,
                'workspace_id' => (int) $result['membership']->organisation_id,
                'role' => $result['membership']->role instanceof OrganisationRole
                    ? $result['membership']->role->value
                    : (string) $result['membership']->role,
            ],
            'projects_added' => $result['projects_added'],
            'deferred_vault_grants' => $result['deferred_vault_grants'],
        ], 201);
    }
}
