<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Login 2FA challenge spec §2. Completes a two-factor login challenge
 * issued by POST /login when the user has `two_factor_enabled = true`.
 *
 * Public endpoint — authentication is via the challenge token, not a
 * bearer token. The `tfc_` prefix on the token ensures middleware can
 * reject it at the gate if it's ever accidentally passed as a bearer.
 *
 * Rate-limited to 5 attempts per challenge; after 5 the challenge is
 * burned and the user must re-authenticate with email + password.
 */
class TwoFactorChallengeController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    /**
     * Cross-challenge failure budget shared with TwoFactorController
     * (audit H15). Failures are tracked on the user row so an attacker
     * can't keep starting fresh challenges to dodge the cap.
     */
    private const MAX_FAILED_ATTEMPTS = 10;

    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly TotpService $totp,
        private readonly RecoveryCodeService $recovery,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $hasCode = $request->filled('code');
        $hasRecovery = $request->filled('recovery_code');

        if ($hasCode === $hasRecovery) {
            throw ValidationException::withMessages([
                'code' => ['Exactly one of code or recovery_code must be present.'],
            ]);
        }

        $tokenHash = hash('sha256', $request->string('challenge_token')->toString());

        /** @var TwoFactorChallenge|null $challenge */
        $challenge = TwoFactorChallenge::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($challenge === null || $challenge->isExpired()) {
            $challenge?->delete();

            return response()->json([
                'message' => 'Challenge token is invalid or expired. Please log in again.',
                'code' => 'challenge_expired',
            ], 401);
        }

        if ($challenge->attempts >= self::MAX_ATTEMPTS) {
            $challenge->delete();

            return response()->json([
                'message' => 'Too many failed attempts. Please log in again.',
                'code' => 'too_many_attempts',
            ], 429);
        }

        $challenge->increment('attempts');

        /** @var User $user */
        $user = User::query()->whereKey($challenge->user_id)->firstOrFail();

        // Cross-challenge lockout (audit H15) — persisted on users.*,
        // so it survives a fresh /login + challenge cycle.
        if ($lockResponse = $this->lockoutResponseIfLocked($user)) {
            return $lockResponse;
        }

        if ($hasCode) {
            $step = $this->totp->verifyStep($user->two_factor_secret, $request->string('code')->toString());
            if ($step === null) {
                $this->recordFailedAttempt($user);

                return response()->json([
                    'message' => 'The code you entered is not valid.',
                    'code' => 'invalid_code',
                ], 401);
            }

            // Replay guard (audit H7): refuse a step we've already
            // consumed. Without this, an attacker who shoulder-surfs
            // a 6-digit code from one tab can replay it in another
            // within the same 30-second window.
            if ($user->last_totp_step !== null && $step <= (int) $user->last_totp_step) {
                $this->recordFailedAttempt($user);

                return response()->json([
                    'message' => 'This code has already been used. Please wait for the next one.',
                    'code' => 'totp_replay',
                ], 401);
            }

            $user->forceFill([
                'last_totp_step' => $step,
                'two_factor_failed_attempts' => 0,
                'two_factor_locked_until' => null,
            ])->save();
        } else {
            if (! $this->recovery->consume($user, $request->string('recovery_code')->toString())) {
                $this->recordFailedAttempt($user);

                return response()->json([
                    'message' => 'Invalid or already-used recovery code.',
                    'code' => 'invalid_recovery_code',
                ], 401);
            }

            $user->forceFill([
                'two_factor_failed_attempts' => 0,
                'two_factor_locked_until' => null,
            ])->save();
        }

        // Success: burn the challenge and issue a real bearer token.
        $challenge->delete();

        $deviceName = $request->string('device_name')->toString() ?: 'default';
        $token = $user->createToken($deviceName)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    private function recordFailedAttempt(User $user): void
    {
        $attempts = ((int) $user->two_factor_failed_attempts) + 1;
        $update = ['two_factor_failed_attempts' => $attempts];

        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $update['two_factor_locked_until'] = Carbon::now()->addMinutes(self::LOCKOUT_MINUTES);
        }

        $user->forceFill($update)->save();
    }

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

        $user->forceFill([
            'two_factor_failed_attempts' => 0,
            'two_factor_locked_until' => null,
        ])->save();

        return null;
    }
}
