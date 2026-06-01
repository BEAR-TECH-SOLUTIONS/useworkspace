<?php

namespace App\Services\Licensing;

use RuntimeException;

/**
 * Signs a JSON payload as a `base64url(body).base64url(sig)` token
 * using a single Ed25519 keypair. No `alg` header, no algorithm
 * negotiation — the verifier always assumes Ed25519. The format is
 * deliberately the JWT shape minus the header to keep token strings
 * short and to remove the JWT-alg-confusion attack surface entirely.
 */
class Ed25519Signer
{
    public function __construct(private readonly string $secretKey)
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Ed25519 secret key must be exactly '.SODIUM_CRYPTO_SIGN_SECRETKEYBYTES.' bytes');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode license payload');
        }

        $body = $this->base64url($json);
        $sig = $this->base64url(sodium_crypto_sign_detached($body, $this->secretKey));

        return "{$body}.{$sig}";
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
