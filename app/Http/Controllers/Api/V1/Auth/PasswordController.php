<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use App\Services\Sharing\ShareLinkRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    public function __construct(
        private readonly ShareLinkRevoker $shareRevoker,
        private readonly TwoFactorVerification $verification,
    ) {}

    public function update(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Current-password check goes first so we don't leak "you're
        // in a bad 2FA state" to someone who doesn't even know the
        // password. Explicit code so the client can render the inline
        // "current password incorrect" error without string-matching.
        if (! Hash::check($request->string('current_password')->toString(), $user->password_hash)) {
            return response()->json([
                'message' => 'Current password is incorrect',
                'code' => 'invalid_current_password',
            ], 422);
        }

        // Mirror the Require2FA middleware's contract (app/Http/Middleware/
        // Require2FA.php §18) but scope it to users who actually have 2FA
        // enabled — a user without 2FA should still be able to change their
        // password. Applying the '2fa' middleware at the route level would
        // lock those users out with `two_factor_required`.
        if ($user->two_factor_enabled && ! $this->verification->verifiedRequest($request)) {
            return response()->json([
                'message' => 'Please verify your two-factor code before retrying.',
                'code' => 'two_factor_verification_required',
            ], 403);
        }

        $user->forceFill(['password_hash' => Hash::make($request->string('password')->toString())])->save();

        // Nuke every other session so a compromised device can't linger
        // after the user changes the password in response to a breach.
        // `currentAccessToken()` returns a PersonalAccessToken for real
        // bearer requests and a TransientToken under `actingAs()` in
        // tests — only the former has an id to exclude.
        $current = $user->currentAccessToken();
        $currentTokenId = $current instanceof PersonalAccessToken ? $current->id : null;

        $user->tokens()
            ->when($currentTokenId !== null, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        // Housekeeping (Universal Share Links plan §10): if the user is
        // changing their password they're often responding to a perceived
        // breach. Auto-revoking their outstanding share links is a UX
        // safety net — note that the share-link enc_key is independent
        // of the account password, so this is policy, not crypto.
        $this->shareRevoker->revokeAllForCreator($user, 'creator_password_changed');

        return response()->json([
            'message' => 'Password updated',
        ]);
    }
}
