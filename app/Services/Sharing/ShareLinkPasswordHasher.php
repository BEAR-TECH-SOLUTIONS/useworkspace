<?php

namespace App\Services\Sharing;

/**
 * Argon2id hashing for share-link passphrases (non-credential shares).
 *
 * Kept out of the global Hash manager so user-account password hashing
 * stays on its existing bcrypt driver. Argon2id parameters
 * (m=64MiB, t=3, p=1) are appropriate for low-entropy human-chosen
 * passphrases; high-entropy material (the credential-share `auth_proof`)
 * uses sha256 instead — argon2 here would add cost for zero security gain.
 *
 * Plan §9.
 */
class ShareLinkPasswordHasher
{
    private const MEMORY_COST = 65536;   // 64 MiB

    private const TIME_COST = 3;

    private const THREADS = 1;

    public function hash(string $plaintext): string
    {
        return password_hash($plaintext, PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost' => self::TIME_COST,
            'threads' => self::THREADS,
        ]);
    }

    public function verify(string $plaintext, string $hash): bool
    {
        return password_verify($plaintext, $hash);
    }

    public function looksLikeArgon2id(string $hash): bool
    {
        return str_starts_with($hash, '$argon2id$');
    }
}
