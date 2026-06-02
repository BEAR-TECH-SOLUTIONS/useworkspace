<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MemberRole;
use App\Enums\NotificationType;
use App\Enums\ResourceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\SetProjectMemberAccessRequest;
use App\Http\Requests\Projects\StoreProjectMemberRequest;
use App\Http\Requests\Projects\UpdateProjectMemberRequest;
use App\Http\Resources\ProjectMemberAccessResource;
use App\Http\Resources\ProjectMemberResource;
use App\Models\Identity\Organisation;
use App\Models\Permissions\ResourcePermission;
use App\Models\Project\Project;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Project membership CRUD. After Step A there is no `project_members`
 * table — a "project member" is a user with a project-level
 * `resource_permissions` row (resource_type='project'). This controller
 * reads and writes through that table only.
 *
 * Users with only child-level grants (Pattern B — a specific vault or
 * board) do not appear here; they are narrower than a project membership
 * and are managed via the per-resource grant endpoints.
 */
class ProjectMemberController extends Controller
{
    public function __construct(
        private readonly PermissionService $perms,
        private readonly NotificationService $notifications,
    ) {}

    public function index(Project $project): AnonymousResourceCollection
    {
        $this->authorize('view', $project);

        $members = ResourcePermission::query()
            ->with('user')
            ->where('resource_type', ResourceType::Project->value)
            ->where('resource_id', $project->id)
            ->orderBy('created_at')
            ->get();

        return ProjectMemberResource::collection($members);
    }

    public function store(StoreProjectMemberRequest $request, Project $project): JsonResponse
    {
        $this->authorize('share', $project);

        $email = $request->string('email')->toString();
        /** @var User $target */
        $target = User::query()->where('email', $email)->firstOrFail();
        $role = MemberRole::from($request->string('role')->toString());

        $permission = $this->perms->grant($request->user(), $target, $project, $role);

        return response()->json([
            'member' => new ProjectMemberResource($permission->load('user')),
        ], 201);
    }

    public function update(UpdateProjectMemberRequest $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('share', $project);

        $role = MemberRole::from($request->string('role')->toString());

        // The original project creator's access is immutable — they must
        // always remain an Owner. Any other role is rejected.
        if ($user->id === $project->original_owner_id && $role !== MemberRole::Owner) {
            return response()->json([
                'message' => "The project creator's role cannot be changed.",
                'code' => 'original_owner_immutable',
            ], 403);
        }

        // The workspace owner (organisations.owner_id) is the
        // workspace-wide invariant — they must remain an Owner on
        // every project in their workspace. Mirrors the
        // original_owner_immutable behaviour above.
        if ($user->id === (int) $project->organisation?->owner_id && $role !== MemberRole::Owner) {
            return response()->json([
                'message' => "The workspace owner's role cannot be changed.",
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        // grant() is updateOrCreate-semantic on (user, resource_type, resource_id),
        // so calling it again is the canonical way to rewrite the role.
        $permission = $this->perms->grant($request->user(), $user, $project, $role);

        return response()->json([
            'member' => new ProjectMemberResource($permission->load('user')),
        ]);
    }

    /**
     * Single-request replacement for a user's access inside this project.
     * Runs in one DB transaction — the legacy POST/DELETE/PATCH dance
     * across three routes left the server in a half-state when any step
     * failed. See CLAUDE.md Members & Permissions spec §2.
     */
    public function access(SetProjectMemberAccessRequest $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('share', $project);

        if ($user->id === $project->original_owner_id) {
            return response()->json([
                'message' => "The project creator's access can't be changed.",
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($user->id === (int) $project->organisation?->owner_id) {
            return response()->json([
                'message' => "The workspace owner's access can't be changed.",
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        $mode = $request->string('mode')->toString();
        $projectRole = $request->filled('project_role')
            ? MemberRole::from($request->string('project_role')->toString())
            : null;
        /** @var array<int, array{type:string,id:int,role:string,encrypted_key?:?string}> $resources */
        $resources = (array) $request->input('resources', []);

        $this->perms->setMemberAccess(
            $request->user(),
            $user,
            $project,
            $mode,
            $projectRole,
            $resources,
        );

        // `mode=none` revokes every grant the user had inside the
        // project — functionally equivalent to destroy() without
        // keep_resource_grants. Fire the same notification here so a
        // client that drives removal via the unified `access` mutation
        // still triggers the inbox entry.
        if ($mode === 'none') {
            $this->notifyMemberRemoved($request->user(), $user, $project);
        }

        return response()->json([
            'member' => new ProjectMemberAccessResource($user, $project),
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request, Project $project, User $user): JsonResponse
    {
        $this->authorize('share', $project);

        if ($user->id === $project->original_owner_id) {
            return response()->json([
                'message' => "The project creator can't be removed.",
                'code' => 'original_owner_immutable',
            ], 403);
        }

        if ($user->id === (int) $project->organisation?->owner_id) {
            return response()->json([
                'message' => "The workspace owner can't be removed from a project in their workspace.",
                'code' => 'workspace_owner_immutable',
            ], 403);
        }

        // keep_resource_grants lets the caller demote a project member
        // to a resource-only grantee: drop the project-level row but
        // leave any direct vault/board/bucket grants (and their wrapped
        // keys) intact. Default is the legacy clean-cut behavior.
        $keepResourceGrants = $request->boolean('keep_resource_grants');

        if ($keepResourceGrants) {
            $this->perms->revokeProjectLevelOnly($user, $project, $request->user());
        } else {
            // revokeRecursive wipes the project-level row AND every child
            // grant in the same project (vault/board/bucket), plus every
            // resource_keys row under the project — the clean-cut departure.
            $this->perms->revokeRecursive($user, $project, $request->user());
        }

        $this->notifyMemberRemoved($request->user(), $user, $project);

        return response()->json(status: 204);
    }

    private function notifyMemberRemoved(?User $actor, User $removed, Project $project): void
    {
        if ($actor !== null && (int) $actor->id === (int) $removed->id) {
            return;
        }

        $workspace = Organisation::query()->whereKey($project->organisation_id)->first();

        $this->notifications->create(
            userId: $removed->id,
            type: NotificationType::MemberRemoved,
            title: 'Your access to "'.$project->name.'" was removed',
            body: $actor !== null ? 'Removed by '.$actor->name : null,
            actor: $actor,
            workspace: $workspace,
            project: $project,
            resourceType: 'project',
            resourceId: $project->id,
            metadata: [],
        );
    }
}