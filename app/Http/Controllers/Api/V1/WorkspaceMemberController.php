<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\OrganisationRole;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceMemberRequest;
use App\Http\Resources\WorkspaceMemberResource;
use App\Models\Identity\Organisation;
use App\Models\Identity\OrganisationMember;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Workspace directory + role/removal. Any workspace member can read the
 * directory (the project-invite dropdown needs it). Role changes and
 * removal are admin-only.
 *
 * Removing a member cascades to every project grant + wrapped vault
 * key that user holds inside this workspace. Mirrors the `mode:"none"`
 * semantics on PUT /projects/{p}/members/{u}/access but applied across
 * every project in the workspace in one transaction.
 */
class WorkspaceMemberController extends Controller
{
    public function __construct(private readonly PermissionService $perms) {}

    public function index(Request $request, Organisation $workspace): AnonymousResourceCollection
    {
        $this->authorize('view', $workspace);

        $members = OrganisationMember::query()
            ->with('user')
            ->where('organisation_id', $workspace->id)
            ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('joined_at')
            ->get();

        return WorkspaceMemberResource::collection($members);
    }

    /**
     * Aggregate a member's access map across every project in the
     * workspace. Single-query replacement for the client's legacy
     * 4N+M round-trip dance when opening the "Edit access" dialog.
     *
     * Projects the user has no grants on are omitted. For each project
     * they DO have grants on:
     *   - A `resource_type='project'` row → mode=project (cascade).
     *   - Otherwise → mode=resources, listing every direct
     *     vault/board/bucket grant.
     *
     * `resource_permissions.project_id` is denormalised on every row
     * (CLAUDE §5), so one filtered scan gives us everything without
     * the vault/board/bucket join the spec sketched.
     */
    public function access(Request $request, Organisation $workspace, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        // Target must be a workspace member — otherwise the "edit
        // access" dialog has no subject. Return 404 so the client
        // doesn't silently render an empty state.
        $isMember = OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('user_id', $user->id)
            ->exists();

        abort_unless($isMember, 404);

        $workspaceProjectIds = Project::query()
            ->where('organisation_id', $workspace->id)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $rows = $workspaceProjectIds === []
            ? collect()
            : ResourcePermission::query()
                ->where('user_id', $user->id)
                ->whereIn('project_id', $workspaceProjectIds)
                ->get(['resource_type', 'resource_id', 'project_id', 'role']);

        // Bucket rows by project_id.
        $byProject = [];
        foreach ($rows as $row) {
            $projectId = (int) $row->project_id;
            $byProject[$projectId][] = $row;
        }

        $projectNames = $byProject === []
            ? []
            : Project::query()
                ->whereIn('id', array_keys($byProject))
                ->pluck('name', 'id')
                ->all();

        $projects = [];
        foreach ($byProject as $projectId => $projectRows) {
            $projectRow = null;
            $resourceRows = [];

            foreach ($projectRows as $r) {
                $type = $r->resource_type instanceof ResourceType
                    ? $r->resource_type
                    : ResourceType::from((string) $r->resource_type);

                if ($type === ResourceType::Project) {
                    $projectRow = $r;
                } else {
                    $resourceRows[] = ['row' => $r, 'type' => $type];
                }
            }

            if ($projectRow !== null) {
                $role = $projectRow->role instanceof MemberRole
                    ? $projectRow->role->value
                    : (string) $projectRow->role;

                $projects[] = [
                    'project_id' => $projectId,
                    'project_name' => $projectNames[$projectId] ?? null,
                    'mode' => 'project',
                    'project_role' => $role,
                    'resources' => null,
                ];

                continue;
            }

            $projects[] = [
                'project_id' => $projectId,
                'project_name' => $projectNames[$projectId] ?? null,
                'mode' => 'resources',
                'project_role' => null,
                'resources' => array_map(static function (array $entry): array {
                    /** @var ResourcePermission $row */
                    $row = $entry['row'];
                    /** @var ResourceType $type */
                    $type = $entry['type'];

                    return [
                        'type' => $type->value,
                        'id' => (int) $row->resource_id,
                        'role' => $row->role instanceof MemberRole
                            ? $row->role->value
                            : (string) $row->role,
                    ];
                }, $resourceRows),
            ];
        }

        return response()->json([
            'access' => [
                'user_id' => (int) $user->id,
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'public_key' => $user->public_key,
                ],
                'projects' => $projects,
            ],
        ]);
    }

    public function update(UpdateWorkspaceMemberRequest $request, Organisation $workspace, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        $newRole = OrganisationRole::from($request->string('role')->toString());

        // The workspace owner's admin role is immutable (mirrors the
        // `original_owner` invariant on projects). Demoting them would
        // leave a workspace with no guaranteed admin.
        if ($workspace->owner_id === (int) $user->id && $newRole !== OrganisationRole::Admin) {
            return response()->json([
                'message' => "The workspace owner's role can't be changed.",
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        /** @var OrganisationMember|null $member */
        $member = OrganisationMember::query()
            ->where('organisation_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        abort_if($member === null, 404);

        $member->role = $newRole->value;
        $member->save();

        return response()->json([
            'member' => new WorkspaceMemberResource($member->fresh()->load('user')),
        ]);
    }

    public function destroy(Request $request, Organisation $workspace, User $user): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        if ($workspace->owner_id === (int) $user->id) {
            return response()->json([
                'message' => "The workspace owner can't be removed.",
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        // Self-removal is a separate flow (leave workspace). An admin
        // deleting themselves via the manage-members route would be
        // surprising UX and is almost always an accident.
        if ((int) $user->id === (int) $request->user()->id) {
            return response()->json([
                'message' => 'Use the leave-workspace flow to remove yourself.',
                'code' => 'cannot_remove_self',
            ], 403);
        }

        DB::transaction(function () use ($workspace, $user, $request): void {
            // Wipe every project grant + wrapped key the user holds in
            // any project inside this workspace. revokeRecursive handles
            // the per-project cascade (resource_permissions across all
            // types + resource_keys).
            Project::query()
                ->where('organisation_id', $workspace->id)
                ->pluck('id')
                ->each(function (int $projectId) use ($user, $request): void {
                    /** @var Project $project */
                    $project = Project::query()->whereKey($projectId)->first();
                    if ($project !== null) {
                        $this->perms->revokeRecursive($user, $project, $request->user());
                    }
                });

            OrganisationMember::query()
                ->where('organisation_id', $workspace->id)
                ->where('user_id', $user->id)
                ->delete();

            // Drop any deferred vault-key grants the user had pending
            // in this workspace. A removed member no longer has the
            // project/board/bucket access those rows were meant to
            // complete, so the rows are just noise in the admin inbox
            // if we leave them. Scoped to this workspace so unrelated
            // deferrals in other workspaces the user still belongs to
            // survive.
            \App\Models\Permissions\DeferredAccessGrant::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->delete();
        });

        return response()->json(status: 204);
    }
}
