<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConsumeRecoveryCodeRequest;
use App\Http\Requests\Auth\DisableTwoFactorRequest;
use App\Http\Requests\Auth\VerifyTwoFactorRequest;
use App\Http\Requests\Auth\VerifyTwoFactorWithPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use App\Services\Auth\TwoFactorVerification;
use App\Services\Sharing\ShareLinkRevoker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Two-factor authentication flow (CLAUDE.md §7).
 *
 * Flow:
 *   1. `POST /auth/2fa/enroll` — generate + store a pending secret,
 *      return `{ secret, otpauth_uri }` for QR rendering.
 *   2. `POST /auth/2fa/confirm` `{ code }` — verify the first code,
 *      flip `two_factor_enabled`, return plaintext recovery codes once.
 *   3. `POST /auth/2fa/verify` `{ code }` — subsequent verifications set a
 *      10-minute cache flag used by the Require2FA middleware.
 *   4. `POST /auth/2fa/recover` `{ recovery_code }` — same as `verify` but
 *      consumes a single-use recovery code instead of a TOTP code.
 *   5. `DELETE /auth/2fa` — disables 2FA. Gated by the Require2FA middleware
 *      so you cannot disable without proving possession.
 *
 * Verification freshness lives in the cache under `2fa_verified:{user_id}`
 * and lasts 10 minutes; middleware checks it on every sensitive request.
 */
class TwoFactorController extends Controller
{
    private const VERIFICATION_TTL_MINUTES = 10;

    /**
     * Cross-challenge failure budget (audit H15). Failures persisted on
     * the user row survive challenge churn, so an attacker cannot rotate
     * fresh TwoFactorChallenge rows to dodge the per-challenge cap.
     */
    private const MAX_FAILED_ATTEMPTS = 10;

    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recovery,
        private readonly ShareLinkRevoker $shareRevoker,
        private readonly TwoFactorVerification $verification,
    ) {}

    public function enroll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return response()->json([
                'message' => '2FA is already enabled on this account. Disable it first to re-enrol.',
                'code' => 'two_factor_already_enabled',
            ], 409);
        }

        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri(
                $secret,
                $user->email,
                config('app.name', 'TeamCore'),
            ),
        ]);
    }

    public function confirm(VerifyTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return response()->json([
                'message' => '2FA is already enabled.',
                'code' => 'two_factor_already_enabled',
            ], 409);
        }

        if ($user->two_factor_secret === null) {
            return response()->json([
                'message' => 'Start enrolment with POST /auth/2fa/enroll first.',
                'code' => 'two_factor_not_enrolled',
            ], 409);
        }

        $step = $this->totp->verifyStep($user->two_factor_secret, $request->string('code')->toString());
        if ($step === null) {
            return response()->json([
                'message' => 'The code you entered is not valid.',
                'errors' => ['code' => ['Invalid TOTP code.']],
            ], 422);
        }

        $codes = $this->recovery->generate();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes['hashed'],
            'last_totp_step' => $step,
        ])->save();

        $this->markVerified($user);

        return response()->json([
            'user' => new UserResource($user->refresh()),
            // The plaintext codes appear here exactly once. If the user loses
            // them they can regenerate a fresh set via POST /auth/2fa/verify
            // followed by the regenerate endpoint (not yet exposed).
            'recovery_codes' => $codes['plain'],
        ]);
    }

    public function verify(VerifyTwoFactorWithPasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->two_factor_enabled || $user->two_factor_secret === null) {
            return response()->json([
                'message' => '2FA is not enabled on this account.',
                'code' => 'two_factor_not_enabled',
            ], 409);
        }

        if ($lockResponse = $this->lockoutResponseIfLocked($user)) {
            return $lockResponse;
        }

        $step = $this->totp->verifyStep($user->two_factor_secret, $request->string('code')->toString());

        if ($step === null) {
            $this->recordFailedAttempt($user);

            return response()->json([
                'message' => 'The code you entered is not valid.',
                'errors' => ['code' => ['Invalid TOTP code.']],
            ], 422);
        }

        // Replay guard (audit H7): the same code is valid for ~30s,
        // and our ±1 window means there's a brief window where a
        // captured code could be re-used. Persist the matched step
        // and refuse anything ≤ it.
        if ($user->last_totp_step !== null && $step <= (int) $user->last_totp_step) {
            $this->recordFailedAttempt($user);

            return response()->json([
                'message' => 'This code has already been used. Please wait for the next one.',
                'errors' => ['code' => ['Code already consumed.']],
                'errorCode' => 'totp_replay',
            ], 422);
        }

        $user->forceFill([
            'last_totp_step' => $step,
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        $this->markVerified($user);

        return response()->json([
            'verified_until' => now()->addMinutes(self::VERIFICATION_TTL_MINUTES)->toIso8601String(),
        ]);
    }

    public function recover(ConsumeRecoveryCodeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->two_factor_enabled) {
            return response()->json([
                'message' => '2FA is not enabled on this account.',
                'code' => 'two_factor_not_enabled',
            ], 409);
        }

        if ($lockResponse = $this->lockoutResponseIfLocked($user)) {
            return $lockResponse;
        }

        if (! $this->recovery->consume($user, $request->string('recovery_code')->toString())) {
            $this->recordFailedAttempt($user);

            return response()->json([
                'message' => 'Invalid or already-used recovery code.',
                'errors' => ['recovery_code' => ['Invalid or already-used recovery code.']],
            ], 422);
        }

        $user->forceFill([
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        $this->markVerified($user);

        // Housekeeping (Universal Share Links plan §10): consuming a
        // recovery code suggests the user lost their TOTP device. Auto-
        // revoke their active share links — they may have been
        // generated before the device was compromised. Policy, not crypto.
        $this->shareRevoker->revokeAllForCreator($user, 'two_factor_recovery_used');

        return response()->json([
            'verified_until' => now()->addMinutes(self::VERIFICATION_TTL_MINUTES)->toIso8601String(),
            'remaining_recovery_codes' => count($user->refresh()->two_factor_recovery_codes ?? []),
        ]);
    }

    public function disable(DisableTwoFactorRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_enabled' => false,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
            'last_totp_step' => null,
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        $this->verification->forget($user, $this->verification->tokenIdFromRequest($request));

        return response()->json([
            'user' => new UserResource($user->refresh()),
        ]);
    }

    private function markVerified(User $user): void
    {
        // Token-scoped flag (audit L8). We derive token id from the
        // current request via the verification helper so callers
        // don't have to plumb it through.
        $this->verification->mark(
            $user,
            $this->verification->tokenIdFromRequest(request()),
        );
    }

    /**
     * Bump the user-row failure counter and lock the account for
     * LOCKOUT_MINUTES once the budget is exhausted. Persisting on
     * users.* (not on TwoFactorChallenge) means an attacker cannot
     * dodge the budget by repeatedly issuing fresh challenges —
     * audit H15.
     */
    private function recordFailedAttempt(User $user): void
    {
        $attempts = ((int) $user->two_factor_failed_attempts) + 1;
        $update = ['two_factor_failed_attempts' => $attempts];

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $update['two_factor_locked_until'] = Carbon::now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->forceFill($update)->save();
    }

    /**
     * Returns a 423 Locked response if the user is currently in the
     * lockout window, else null.
     */
    private function lockoutResponseIfLocked(User $user): ?JsonResponse
    {
        $until = $user->two_factor_locked_until;
        if ($until === null) {
            return null;
        }

        $untilDt = $until instanceof \DateTimeInterface ? Carbon::instance($until) : Carbon::parse((string) $until);
        if ($untilDt->isFuture()) {
            return response()->json([
                'message' => 'Too many failed attempts. Try again after the cooldown.',
                'code' => 'two_factor_locked',
                'locked_until' => $untilDt->toIso8601String(),
            ], 423);
        }

        // Cooldown elapsed — clear lock so this call can proceed.
        $user->forceFill([
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        return null;
    }
}
