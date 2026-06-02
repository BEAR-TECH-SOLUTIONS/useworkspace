<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationType;
use App\Enums\OrganisationRole;
use App\Enums\WorkspaceInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\CreateWorkspaceInvitationRequest;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Models\Identity\Organisation;
use App\Models\WorkspaceInvitation;
use App\Services\Notifications\NotificationService;
use App\Contracts\PlanLimits;
use App\Services\Workspaces\WorkspaceInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin-side: create, list, and cancel workspace invitations.
 */
class WorkspaceInvitationController extends Controller
{
    public function __construct(
        private readonly WorkspaceInvitationService $invitations,
        private readonly NotificationService $notifications,
        private readonly PlanLimits $plans,
    ) {}

    public function index(Request $request, Organisation $workspace): AnonymousResourceCollection
    {
        $this->authorize('manageMembers', $workspace);

        $query = WorkspaceInvitation::query()
            ->with(['inviter'])
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return WorkspaceInvitationResource::collection($query->get());
    }

    public function store(CreateWorkspaceInvitationRequest $request, Organisation $workspace): JsonResponse
    {
        // Admins always can; members can iff the workspace's
        // `members_can_invite_members` toggle is on.
        $this->authorize('inviteMembers', $workspace);
        $this->plans->assertCanAddMember($workspace);

        $role = OrganisationRole::from($request->string('role')->toString());

        $invitation = $this->invitations->create(
            $workspace,
            $request->user(),
            $request->string('email')->toString(),
            $role,
            (array) $request->input('projects', []),
        );

        // Notify the invitee only when they already have a TeamCore
        // account (invitee_id is set). New users get the email flow
        // and will see the invitation via /me/workspace-invitations
        // after sign-up — there's no user_id to notify yet.
        if ($invitation->invitee_id !== null) {
            $this->notifications->create(
                userId: (int) $invitation->invitee_id,
                type: NotificationType::InvitationReceived,
                title: ($request->user()?->name ?? 'Someone').' invited you to "'.$workspace->name.'"',
                body: 'Role: '.$role->value,
                actor: $request->user(),
                workspace: $workspace,
                project: null,
                resourceType: 'invitation',
                resourceId: $invitation->id,
                metadata: [
                    'invitation_id' => $invitation->id,
                    'role' => $role->value,
                ],
            );
        }

        return response()->json([
            'invitation' => new WorkspaceInvitationResource($invitation->load([
                'inviter',
                'projectGrants.project',
                'projectGrants.resourceGrants',
                'projectGrants.vaultKeys',
            ])),
        ], 201);
    }

    public function destroy(Request $request, Organisation $workspace, WorkspaceInvitation $invitation): JsonResponse
    {
        $this->authorize('manageMembers', $workspace);

        abort_if((int) $invitation->workspace_id !== (int) $workspace->id, 404);

        $this->invitations->cancel($invitation);

        return response()->json(status: 204);
    }
}
