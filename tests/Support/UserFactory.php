<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Tiny test helper. Avoids pulling in a full Eloquent factory class for the
 * handful of fields we need across feature tests.
 *
 * Most tests want a user who has already completed the master-password setup
 * step, so the default `create()` path leaves the crypto bundle populated and
 * the EnsureMasterPasswordSet middleware happy. Use `createWithoutMasterPassword`
 * to exercise the pre-setup state.
 */
class UserFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function create(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'email' => 'user-'.bin2hex(random_bytes(4)).'@example.com',
            'password_hash' => Hash::make('correct-horse-battery-staple'),
            'master_password_salt' => base64_encode(random_bytes(16)),
            'master_password_verifier' => base64_encode(random_bytes(32)),
            'public_key' => 'pub-'.bin2hex(random_bytes(8)),
            'encrypted_private_key' => 'enc-'.bin2hex(random_bytes(8)),
            'private_key_iv' => base64_encode(random_bytes(12)),
        ], $overrides));
    }

    /**
     * A user that has registered but not yet uploaded their master-password
     * crypto bundle — i.e. everything gated by the EnsureMasterPasswordSet
     * middleware should reject them.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function createWithoutMasterPassword(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test '.bin2hex(random_bytes(3)),
            'email' => 'user-'.bin2hex(random_bytes(4)).'@example.com',
            'password_hash' => Hash::make('correct-horse-battery-staple'),
        ], $overrides));
    }
}
