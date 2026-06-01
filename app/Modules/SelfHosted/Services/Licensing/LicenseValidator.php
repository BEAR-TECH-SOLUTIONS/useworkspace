<?php

namespace App\Modules\SelfHosted\Services\Licensing;

use App\Services\Licensing\Ed25519Verifier;
use Illuminate\Support\Carbon;

/**
 * Offline-only license validator for self-hosted instances. Reads
 * LICENSE_TOKEN from env on construct and re-checks the Ed25519
 * signature against the baked-in public key. Does NOT hit the
 * network — phone-home is a separate, slower background loop.
 *
 * The verifier instance is constructed by AppServiceProvider using
 * licensing/public_key.pem (the file copied into the self-hosted
 * Docker image at build time).
 */
class LicenseValidator
{
    public function __construct(private readonly Ed25519Verifier $verifier) {}

    /**
     * Validate a token string. Returns the decoded payload on
     * success, or an error envelope:
     *
     *   ['valid' => true, 'payload' => [...]]
     *   ['valid' => false, 'reason' => '...']
     *
     * The token's `v` field selects the binding check:
     *
     *   • v1 (legacy admin tokens): pinned to TC_DOMAIN via the
     *     `fingerprint` field. $expectedFingerprint is derived by the
     *     caller from TC_DOMAIN.
     *   • v2 (self-serve tokens): pinned to the install's random
     *     `instance_id`, compared against LICENSE_INSTANCE_ID from the
     *     environment. v2 tokens carry no fingerprint by design (the
     *     domain was dropped for privacy), so $expectedFingerprint is
     *     ignored for them.
     *
     * @return array{valid: true, payload: array<string, mixed>}|array{valid: false, reason: string}
     */
    public function validate(string $token, ?string $expectedFingerprint = null): array
    {
        $result = $this->verifier->verify($token);
        if ($result['ok'] === false) {
            return ['valid' => false, 'reason' => $result['reason']];
        }

        $payload = $result['payload'];

        $expiresAt = $payload['expires_at'] ?? null;
        if (! is_string($expiresAt) || Carbon::parse($expiresAt)->isPast()) {
            return ['valid' => false, 'reason' => 'expired'];
        }

        $version = (int) ($payload['v'] ?? 1);

        if ($version >= 2) {
            return $this->validateV2($payload);
        }

        return $this->validateV1($payload, $expectedFingerprint);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{valid: true, payload: array<string, mixed>}|array{valid: false, reason: string}
     */
    private function validateV1(array $payload, ?string $expectedFingerprint): array
    {
        // Fingerprint pinning (audit H13). v1 tokens are required to
        // carry a fingerprint, and the self-hosted box is required to
        // have TC_DOMAIN set. Either side being null/empty is a hard
        // fail rather than silently skipping the check — a
        // fingerprint-less token would be portable across hosts and
        // re-usable from any leak.
        $fingerprint = $payload['fingerprint'] ?? null;
        if (! is_string($fingerprint) || $fingerprint === '') {
            return ['valid' => false, 'reason' => 'fingerprint_missing'];
        }
        if ($expectedFingerprint === null || $expectedFingerprint === '') {
            return ['valid' => false, 'reason' => 'fingerprint_unset'];
        }
        if (! hash_equals($expectedFingerprint, $fingerprint)) {
            return ['valid' => false, 'reason' => 'fingerprint_mismatch'];
        }

        return ['valid' => true, 'payload' => $payload];
    }

    /**
     * v2 binding: the signed instance_id must match the install's own
     * LICENSE_INSTANCE_ID. Same fail-closed posture as v1's
     * fingerprint — a token with no instance_id, or an install with no
     * configured id, is rejected rather than waved through.
     *
     * @param  array<string, mixed>  $payload
     * @return array{valid: true, payload: array<string, mixed>}|array{valid: false, reason: string}
     */
    private function validateV2(array $payload): array
    {
        $instanceId = $payload['instance_id'] ?? null;
        if (! is_string($instanceId) || $instanceId === '') {
            return ['valid' => false, 'reason' => 'instance_id_missing'];
        }

        $expected = (string) env('LICENSE_INSTANCE_ID', '');
        if ($expected === '') {
            return ['valid' => false, 'reason' => 'instance_id_unset'];
        }
        if (! hash_equals($expected, $instanceId)) {
            return ['valid' => false, 'reason' => 'instance_id_mismatch'];
        }

        return ['valid' => true, 'payload' => $payload];
    }
}
