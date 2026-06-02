<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anti-phishing identity probe. The desktop client sends a fresh
 * random nonce; this endpoint signs it with the install's private
 * Ed25519 key (generated at install time, stored in
 * LICENSE_INSTANCE_PRIVATE_KEY) and returns the signed nonce plus
 * the cloud-issued LICENSE_ATTESTATION envelope.
 *
 * The client verifies:
 *   1. The attestation's signature against the bundled cloud public
 *      key (proves the cloud signed this attestation).
 *   2. The signed_nonce against the instance_public_key embedded in
 *      the attestation payload (proves the responding server holds
 *      the matching private key — not just a leaked copy of the
 *      attestation).
 *
 * Either check failing means the server isn't a legitimate
 * cloud-licensed self-hosted install. Combined, they defeat replay
 * attacks: capturing another install's attestation isn't enough,
 * the attacker would also need that install's private key (which
 * never leaves the install host).
 *
 * The LICENSE_TOKEN — used for cloud phone-home and treated as
 * secret — is intentionally NEVER returned by this endpoint.
 */
class ServerAttestController extends Controller
{
    public function attest(Request $request): JsonResponse
    {
        $request->validate([
            // Random opaque token the client supplies. Min length stops
            // trivial replays; max stops anyone uploading a megabyte to
            // exhaust the signer. Recommend the client use 32 random
            // bytes base64-encoded (~44 chars).
            'nonce' => ['required', 'string', 'min:8', 'max:512'],
        ]);

        $attestation = (string) env('LICENSE_ATTESTATION', '');
        if ($attestation === '') {
            // Either this is the cloud edition (no per-instance
            // attestation) or a self-hosted install that pre-dates
            // the attestation flow / was provisioned via the legacy
            // --license=TOKEN path. Either way the client can't
            // perform cryptographic identity verification here.
            return response()->json([
                'error' => 'no_attestation',
                'message' => 'This server cannot attest its identity (no instance keypair provisioned).',
            ], 503);
        }

        $seedB64 = (string) env('LICENSE_INSTANCE_PRIVATE_KEY', '');
        $seed = base64_decode($seedB64, true);
        if ($seed === false || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            // Misconfiguration: attestation present but the matching
            // private key is missing/malformed. Worth a loud 503 so
            // operators notice on the next probe.
            return response()->json([
                'error' => 'no_instance_key',
                'message' => 'Server attestation key is missing or malformed.',
            ], 503);
        }

        // libsodium's sign_detached wants the 64-byte expanded secret
        // key (seed || pubkey), derived deterministically from the
        // 32-byte seed. Keep both halves zeroized after use — defense
        // in depth, the seed will linger in env-var memory anyway but
        // local vars don't need to.
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $signedNonce = sodium_crypto_sign_detached(
            $request->string('nonce')->toString(),
            $secretKey,
        );

        sodium_memzero($keypair);
        sodium_memzero($secretKey);
        sodium_memzero($seed);

        return response()->json([
            'attestation' => $attestation,
            'signed_nonce' => base64_encode($signedNonce),
        ]);
    }
}
