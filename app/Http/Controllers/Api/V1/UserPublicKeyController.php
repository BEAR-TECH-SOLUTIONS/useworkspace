<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Identity\OrganisationMember;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Enums\WorkspaceInvitationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public-key lookup for the desktop client's invitation crypto. The
 * inviter needs the invitee's RSA public key to wrap the project key.
 *
 * Audit H4: previously this endpoint returned 404 for unknown emails
 * and 200+key for known emails — a clean enumeration oracle. Now:
 *   - Unknown email → 200 with `public_key: null` (indistinguishable
 *     from "user exists but hasn't set up E2E").
 *   - Known email but no shared workspace + no pending invitation
 *     between caller and target → 200 with `public_key: null`
 *     (privacy: don't reveal who's registered to arbitrary callers).
 *   - Known email + relationship → 200 with the actual public key.
 *
 * Email is loosely validated server-side to reject obvious garbage.
 */
class UserPublicKeyController extends Controller
{
    public function __invoke(Request $request, string $email): JsonResponse
    {
        $caller = $request->user();
        $email = strtolower(trim($email));

        // Reject obvious junk before hitting the DB. Permissive on
        // purpose — real validation is "do we have a user with this".
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return self::emptyResponse();
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            return self::emptyResponse();
        }

        // Relationship gate: caller must share at least one workspace
        // with the target, OR have a pending invitation between them
        // (so an inviter can wrap a key for a not-yet-registered
        // teammate they just added). The legacy invitee_id=null case
        // is handled by the second clause via invitee_email.
        $shareWorkspace = OrganisationMember::query()
            ->where('user_id', $caller->id)
            ->whereIn('organisation_id', function ($q) use ($user): void {
                $q->select('organisation_id')
                    ->from('organisation_members')
                    ->where('user_id', $user->id);
            })
            ->exists();

        $hasInvite = WorkspaceInvitation::query()
            ->where('status', WorkspaceInvitationStatus::Pending->value)
            ->where(function ($q) use ($caller, $user, $email): void {
                $q->where(function ($qq) use ($caller, $user, $email): void {
                    $qq->where('inviter_id', $caller->id)
                        ->where(function ($q3) use ($user, $email): void {
                            $q3->where('invitee_id', $user->id)
                                ->orWhereRaw('lower(invitee_email) = ?', [$email]);
                        });
                })->orWhere(function ($qq) use ($caller, $user): void {
                    $qq->where('inviter_id', $user->id)
                        ->where('invitee_id', $caller->id);
                });
            })
            ->exists();

        if (! $shareWorkspace && ! $hasInvite) {
            return self::emptyResponse();
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'public_key' => $user->public_key,
            ],
        ]);
    }

    /**
     * Indistinguishable response shape regardless of whether the
     * email is unknown, the user exists but hasn't completed E2E
     * setup, or the caller has no relationship to the target.
     */
    private static function emptyResponse(): JsonResponse
    {
        return response()->json([
            'user' => [
                'id' => null,
                'email' => null,
                'public_key' => null,
            ],
        ]);
    }
}
