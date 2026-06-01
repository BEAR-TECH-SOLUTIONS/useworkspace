<?php

namespace App\Modules\SelfHosted\Http\Middleware;

use App\Modules\SelfHosted\Models\LicenseState;
use App\Modules\SelfHosted\Services\Licensing\LicenseValidator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Gates every authenticated request on a self-hosted instance.
 *
 * 1. Reads the cached payload from `license_state`. If stale > 1h we
 *    re-verify the token locally (no network — pure Ed25519 against
 *    the baked-in public key) and refresh the cache.
 * 2. Refuses with 503 `license_expired` if `expires_at` is past.
 * 3. Refuses with 503 `license_offline_grace_exceeded` if the last
 *    successful phone-home is older than 7 days. The grace clock
 *    advances only on successful phone-home — network failures do
 *    not reset it, but also do not accelerate it.
 *
 * On `TC_EDITION=cloud` (the default) this middleware short-circuits
 * to allow everything. Single codebase, branch via env.
 */
class LicenseGuard
{
    private const REVERIFY_AFTER = 3600;            // 1 hour
    private const OFFLINE_GRACE_SECONDS = 7 * 86400; // 7 days

    public function __construct(private readonly LicenseValidator $validator) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (config('teamcore.edition') !== 'self_hosted') {
            return $next($request);
        }

        $state = LicenseState::singleton();
        $now = Carbon::now();

        $needsReverify = $state->verified_at === null
            || $state->verified_at->diffInSeconds($now) >= self::REVERIFY_AFTER;

        if ($needsReverify) {
            $token = $state->token !== '' ? $state->token : (string) env('LICENSE_TOKEN', '');
            $result = $this->validator->validate($token, $this->expectedFingerprint());

            if ($result['valid'] === false) {
                return $this->refuse('license_'.$result['reason'], 503);
            }

            $state->update([
                'token' => $token,
                'verified_payload' => $result['payload'],
                'verified_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $payload = is_array($state->verified_payload) ? $state->verified_payload : [];

        if (isset($payload['expires_at']) && Carbon::parse($payload['expires_at'])->isPast()) {
            return $this->refuse('license_expired', 503);
        }

        // Revocation propagated by the most recent phone-home: refuse
        // immediately, don't wait for the offline-grace clock (audit
        // C8). The phone-home stamps `last_phone_home_code = 'revoked'`
        // when the central endpoint returns `valid: false, reason:
        // 'revoked'`. Treat any *_revoked code suffix the same way
        // for forward compatibility (e.g. `local_revoked`).
        $code = (string) ($state->last_phone_home_code ?? '');
        if ($code === 'revoked' || str_ends_with($code, '_revoked')) {
            return $this->refuse('license_revoked', 503);
        }

        if (
            $state->last_phone_home_ok === false
            && $state->last_phone_home_at !== null
            && $state->last_phone_home_at->diffInSeconds($now) > self::OFFLINE_GRACE_SECONDS
        ) {
            return $this->refuse('license_offline_grace_exceeded', 503);
        }

        return $next($request);
    }

    private function expectedFingerprint(): ?string
    {
        $domain = (string) env('TC_DOMAIN', '');

        return $domain === '' ? null : "domain:{$domain}";
    }

    private function refuse(string $code, int $status): JsonResponse
    {
        return response()->json([
            'code' => $code,
            'message' => $this->messageFor($code),
        ], $status);
    }

    private function messageFor(string $code): string
    {
        return match ($code) {
            'license_expired' => 'This installation\'s license has expired. Contact your administrator.',
            'license_revoked' => 'This installation\'s license has been revoked. Contact your administrator.',
            'license_signature_mismatch', 'license_malformed' => 'License token failed local verification.',
            'license_fingerprint_mismatch' => 'License token does not match this installation\'s domain.',
            'license_instance_id_mismatch', 'license_instance_id_missing', 'license_instance_id_unset' => 'License token is not bound to this installation. Re-run the installer to claim a fresh token.',
            'license_offline_grace_exceeded' => 'License could not be re-validated for over 7 days. Restore network connectivity to the central licence server, or contact your administrator.',
            default => 'License is not valid for this installation.',
        };
    }
}
