<?php

namespace App\Http\Resources\Sharing;

use App\Models\Vault\Credential;

/**
 * Frozen JSON snapshot of a Credential for a public share link.
 *
 * The encrypted blob, IV, and key salt are CLIENT-SUPPLIED — the server
 * never sees plaintext credential data and never derives the share's
 * encryption key. Server-visible plaintext metadata (name, url, type,
 * tags) is consistent with what the credentials table already stores
 * unencrypted (CLAUDE.md §2.4).
 */
final class CredentialShareSnapshot
{
    /**
     * @param  array{encrypted_blob: string, blob_iv: string, key_salt: string}  $crypto
     * @return array<string, mixed>
     */
    public static function forResource(Credential $credential, array $crypto): array
    {
        return [
            'id' => (int) $credential->id,
            'type' => $credential->type?->value,
            'name' => (string) $credential->name,
            'url' => $credential->url,
            'tags' => $credential->tags ?? [],
            'encrypted_blob' => $crypto['encrypted_blob'],
            'blob_iv' => $crypto['blob_iv'],
            'key_salt' => $crypto['key_salt'],
        ];
    }
}
