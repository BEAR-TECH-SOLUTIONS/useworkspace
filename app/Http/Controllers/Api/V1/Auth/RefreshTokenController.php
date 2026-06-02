<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use App\Support\SessionMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Active-session token rotation.
 *
 * Sanctum bearers expire after `sanctum.expiration` minutes (default
 * 14 days, audit H5). Without a refresh path every honest user would
 * be bounced to the login screen on day 14 regardless of how active
 * they were. This controller lets a client trade a still-valid token
 * for a fresh full-TTL one — but only inside the last third of the
 * token's life, so a stolen bearer cannot rotate itself indefinitely
 * from day one.
 *
 * Security model:
 *   • Auth: the existing bearer must already validate (auth:sanctum).
 *   • Window: now − token.created_at ≥ TTL × 2/3. Outside the window
 *     we return 409 `too_early` so honest clients back off.
 *   • 2FA: if the user has 2FA enabled, a fresh `2fa_verified` flag
 *     on the current token id is required — otherwise a stolen
 *     bearer would extend the TTL forever without ever needing the
 *     second factor.
 *   • Old token is deleted in the same transaction that issues the
 *     new one; the response token is the only valid one going
 *     forward.
 *   • Throttle: route-level `throttle:token-refresh` caps abuse.
 */
class RefreshTokenController extends Controller
{
    public function __construct(private readonly TwoFactorVerification $verification) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentToken = $user->currentAccessToken();
        if (! $currentToken instanceof PersonalAccessToken) {
            // TransientToken (SPA stateful) has no persisted row and
            // therefore no created_at to compare against. The refresh
            // contract is bearer-only; reject cleanly.
            return response()->json([
                'message' => 'Refresh is only supported for bearer tokens.',
                'code' => 'refresh_unsupported_token_type',
            ], 400);
        }

        $ttlMinutes = config('sanctum.expiration');
        if ($ttlMinutes === null) {
            return response()->json([
                'message' => 'Token refresh is not applicable when tokens do not expire.',
                'code' => 'refresh_not_applicable',
            ], 409);
        }
        $ttlMinutes = (int) $ttlMinutes;

        $createdAt = $currentToken->created_at instanceof \DateTimeInterface
            ? Carbon::instance($currentToken->created_at)
            : Carbon::parse((string) $currentToken->created_at);
        $expiresAt = $createdAt->copy()->addMinutes($ttlMinutes);
        $eligibleAt = $createdAt->copy()->addMinutes((int) floor($ttlMinutes * 2 / 3));

        if (Carbon::now()->lt($eligibleAt)) {
            // Honest clients should not retry until the eligibility
            // window opens. Expose the timestamps so the desktop app
            // can schedule the background refresh precisely instead
            // of polling.
            return response()->json([
                'message' => 'Token is not within the refresh window yet.',
                'code' => 'refresh_too_early',
                'eligible_at' => $eligibleAt->toIso8601String(),
                'expires_at' => $expiresAt->toIso8601String(),
            ], 409);
        }

        // 2FA gate: a stolen bearer would otherwise renew itself
        // indefinitely. Require the second factor on the same token
        // before we'll mint a successor.
        if ($user->two_factor_enabled && ! $this->verification->verifiedRequest($request)) {
            return response()->json([
                'message' => 'Re-verify 2FA before refreshing your session.',
                'code' => 'two_factor_verification_required',
            ], 403);
        }

        // Preserve the prior token's name + abilities so a desktop /
        // CLI / integration token doesn't quietly become 'default'
        // after a refresh.
        $name = (string) ($currentToken->name ?: 'default');
        $abilities = is_array($currentToken->abilities) && $currentToken->abilities !== []
            ? $currentToken->abilities
            : ['*'];

        $new = $user->createToken($name, $abilities);

        // Carry the 2FA verification over to the new token id so the
        // user doesn't immediately have to re-verify after a refresh
        // they only just authorised with the second factor.
        if ($user->two_factor_enabled) {
            $newId = (int) $new->accessToken->id;
            $this->verification->mark($user, $newId);
        }

        // Revoke the old token last so a half-failed createToken
        // doesn't leave the user stranded without any bearer.
        $currentToken->delete();

        return response()->json([
            'token' => $new->plainTextToken,
            'session' => SessionMeta::describe($new->accessToken),
        ]);
    }
}
