<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganisationRole;
use App\Enums\WorkspaceInvitationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceInvitationResource;
use App\Http\Resources\WorkspaceMemberResource;
use App\Http\Resources\WorkspaceResource;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Contracts\PlanLimits;
use App\Services\Auth\TwoFactorVerification;
use App\Services\Workspaces\WorkspaceInvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Invitee-side workspace invitations. Not scoped under a workspace —
 * the invitee may not belong to it yet. Auth is based solely on the
 * invitation email matching the current user's email (or invitee_id
 * matching when we already linked the account at create-time).
 */
class MeWorkspaceInvitationController extends Controller
{
    public function __construct(
        private readonly WorkspaceInvitationService $invitations,
        private readonly PlanLimits $plans,
        private readonly TwoFactorVerification $verification,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $invitations = WorkspaceInvitation::query()
            ->with(['inviter', 'workspace'])
            ->where('status', WorkspaceInvitationStatus::Pending->value)
            ->where(function ($q) use ($user): void {
                $q->where('invitee_id', $user->id)
                    ->orWhereRaw('lower(invitee_email) = ?', [strtolower($user->email)]);
            })
            ->orderByDesc('created_at')
            ->get();

        return WorkspaceInvitationResource::collection($invitations);
    }

    public function accept(Request $request, int $invitation): JsonResponse
    {
        $user = $request->user();

        /** @var WorkspaceInvitation|null $row */
        $row = WorkspaceInvitation::query()->find($invitation);

        if ($gate = $this->gateAccess($row, $request, $user)) {
            return $gate;
        }

        // Audit M4: accepting an Admin invitation is a privilege
        // escalation event; require a fresh 2FA proof first so a
        // stolen bearer can't silently elevate the attacker into an
        // Admin seat just because they happened to be the invitee.
        // Falls back gracefully when the user hasn't enabled 2FA —
        // we refuse with a distinct code so the client can prompt
        // for enrolment first.
        if ($row->role === OrganisationRole::Admin) {
            if (! $user->two_factor_enabled) {
                return response()->json([
                    'code' => 'two_factor_required_for_admin',
                    'message' => 'Enable 2FA on your account before accepting an Admin invitation.',
                ], 403);
            }
            if (! $this->verification->verifiedRequest($request)) {
                return response()->json([
                    'code' => 'two_factor_proof_required',
                    'message' => 'Re-verify 2FA before accepting an Admin invitation.',
                ], 403);
            }
        }

        // Re-check plan cap at accept-time — the workspace may have
        // downgraded between issuing and accepting the invite.
        $this->plans->assertCanAddMember($row->workspace);

        $result = $this->invitations->accept($row, $user);
        /** @var \App\Models\Identity\OrganisationMember $member */
        $member = $result['member'];
        $member->load('user');

        $workspace = $row->fresh()->workspace;

        return response()->json([
            'workspace' => new WorkspaceResource($workspace),
            'membership' => new WorkspaceMemberResource($member),
            'projects_added' => $result['projects_added'],
            'warnings' => $result['warnings'],
            'invitation' => new WorkspaceInvitationResource($row->fresh()),
        ]);
    }

    public function decline(Request $request, int $invitation): JsonResponse
    {
        $user = $request->user();

        /** @var WorkspaceInvitation|null $row */
        $row = WorkspaceInvitation::query()->find($invitation);

        if ($gate = $this->gateAccess($row, $request, $user)) {
            return $gate;
        }

        $this->invitations->decline($row);

        return response()->json(status: 204);
    }

    /**
     * Differentiated gate per spec §2 — explicit codes every client
     * toast can key off of. Implicit route binding would give us a
     * bare 404 without the `invitation_not_found` code, so we resolve
     * the model manually and branch here.
     *
     * Authorization model (audit C4 fix):
     *  - If `invitee_id` was set at issuance, the invite is provably
     *    bound to that account — match the authed user's id, no
     *    token required (we don't have one to compare).
     *  - If `invitee_id` is null (user wasn't registered at invite
     *    time), the email match alone is INSUFFICIENT — anyone who
     *    registered the victim's address could otherwise claim the
     *    invite. Require the invitation token from the email link
     *    body param.
     *
     * Returns a JsonResponse to short-circuit, or null when the caller
     * should proceed.
     */
    private function gateAccess(?WorkspaceInvitation $row, Request $request, User $user): ?JsonResponse
    {
        if ($row === null) {
            return response()->json([
                'code' => 'invitation_not_found',
                'message' => 'Invitation not found.',
            ], 404);
        }

        if ($row->status !== WorkspaceInvitationStatus::Pending) {
            return response()->json([
                'code' => 'invitation_not_pending',
                'message' => "This invitation has already been {$row->status->value}.",
            ], 409);
        }

        if ($row->isExpired()) {
            return response()->json([
                'code' => 'invitation_expired',
                'message' => 'This invitation has expired.',
            ], 410);
        }

        $idMatches = $row->invitee_id !== null
            && (int) $row->invitee_id === (int) $user->id;

        $suppliedToken = (string) $request->input('invitation_token', '');
        $tokenMatches = $suppliedToken !== ''
            && hash_equals((string) $row->token, $suppliedToken);

        // Either a positive id match (invite issued to an existing
        // account) OR a token proof (possession of the email link).
        // Email-string equality alone is rejected — that's the
        // register-as-victim primitive we're closing here.
        if (! $idMatches && ! $tokenMatches) {
            return response()->json([
                'code' => 'invitation_invalid_token',
                'message' => $row->invitee_id === null
                    ? 'This invitation requires the token from the email it was sent to.'
                    : "This invitation was sent to {$row->invitee_email}.",
            ], 403);
        }

        return null;
    }
}
