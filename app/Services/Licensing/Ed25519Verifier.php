<?php

namespace App\Services\Licensing;

use RuntimeException;

/**
 * Verifies tokens issued by {@see Ed25519Signer}. Returns the decoded
 * payload on success or a {@see VerificationFailure} value object
 * describing why. Never throws on bad input — the verify endpoint
 * must distinguish "tampered" from "expired" without exception-spew
 * since both are valid 200 results from the central backend's POV.
 */
class Ed25519Verifier
{
    public function __construct(private readonly string $publicKey)
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new RuntimeException('Ed25519 public key must be exactly '.SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES.' bytes');
        }
    }

    /**
     * @return array{ok: true, payload: array<string, mixed>}|array{ok: false, reason: string}
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 2) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        [$body, $sig] = $parts;

        $rawSig = $this->base64urlDecode($sig);
        if ($rawSig === null || strlen($rawSig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        if (! sodium_crypto_sign_verify_detached($rawSig, $body, $this->publicKey)) {
            return ['ok' => false, 'reason' => 'signature_mismatch'];
        }

        $rawBody = $this->base64urlDecode($body);
        if ($rawBody === null) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ['ok' => false, 'reason' => 'malformed'];
        }

        return ['ok' => true, 'payload' => $payload];
    }

    private function base64urlDecode(string $value): ?string
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
