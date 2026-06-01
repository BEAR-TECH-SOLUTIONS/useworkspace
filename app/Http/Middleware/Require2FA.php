<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\TwoFactorVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks sensitive endpoints unless the user has 2FA enabled *and* has
 * verified a code recently (10-minute window, keyed in cache by user id).
 *
 * Two distinct rejection codes so the client can react appropriately:
 *   - `two_factor_required` — 2FA isn't enabled; prompt the user to enrol.
 *   - `two_factor_verification_required` — enabled but stale; prompt for
 *     a TOTP/recovery code and POST /auth/2fa/verify first.
 */
class Require2FA
{
    public function __construct(private readonly TwoFactorVerification $verification) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        if (! $user->two_factor_enabled) {
            return response()->json([
                'message' => 'Two-factor authentication must be enabled for this action.',
                'code' => 'two_factor_required',
            ], 403);
        }

        // Audit L8: scoped to the bearer token id, not just the user
        // id. A stolen secondary token cannot ride a fresh verify
        // proven on a different device.
        if (! $this->verification->verifiedRequest($request)) {
            return response()->json([
                'message' => 'Please verify your two-factor code before retrying.',
                'code' => 'two_factor_verification_required',
            ], 403);
        }

        return $next($request);
    }
}
