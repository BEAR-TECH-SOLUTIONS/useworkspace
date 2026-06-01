<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Fixed bcrypt-12 dummy hash used when an unknown email is
     * submitted, so Hash::check always burns the same CPU regardless
     * of whether the user exists. Closes the timing-based enumeration
     * oracle flagged in audit H4. The plaintext that produced this
     * hash is not recoverable.
     */
    private const TIMING_DUMMY_HASH = '$2y$12$........................NeverM4tch3sAnyR3al.....................';

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();
        $password = $request->string('password')->toString();

        $user = User::query()->where('email', $email)->first();

        // Always run Hash::check so the per-request timing is
        // indistinguishable between "unknown email" and "wrong
        // password". Without this an attacker can enumerate
        // registered emails by measuring response latency.
        $hashToCheck = $user?->password_hash ?? self::TIMING_DUMMY_HASH;
        $passwordMatches = Hash::check($password, $hashToCheck);

        if ($user === null || ! $passwordMatches) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // 2FA gate: if the user has completed 2FA enrolment, issue a
        // short-lived challenge token instead of the bearer token. The
        // client must complete the challenge via POST /auth/2fa/challenge
        // before receiving a real session.
        if ($user->two_factor_enabled) {
            return $this->issueChallenge($user);
        }

        $token = $user->createToken($request->string('device_name')->toString() ?: 'default')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    private function issueChallenge(User $user): JsonResponse
    {
        // Clean up any stale challenges for this user so we don't
        // accumulate orphan rows between logins.
        TwoFactorChallenge::query()
            ->where('user_id', $user->id)
            ->delete();

        $plainToken = 'tfc_'.Str::random(40);
        $expiresAt = Carbon::now()->addMinutes(5);

        TwoFactorChallenge::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'two_factor_required' => true,
            'challenge_token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 202);
    }
}
