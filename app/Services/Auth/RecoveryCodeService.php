<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * One-time recovery codes used when the user loses their TOTP device.
 *
 * Codes are displayed once at enrolment (plaintext, client-side printable),
 * stored as bcrypt hashes so a database leak cannot recover them, and each
 * code is single-use — consumption removes the hash from the list.
 */
class RecoveryCodeService
{
    public const COUNT = 10;

    /**
     * Generate plaintext codes + their hashed counterparts. The plaintext
     * list must be returned to the user exactly once; the hashed list is
     * what's stored on the User model.
     *
     * @return array{plain: array<int, string>, hashed: array<int, string>}
     */
    public function generate(): array
    {
        $plain = [];
        $hashed = [];

        for ($i = 0; $i < self::COUNT; $i++) {
            // Audit L6: bumped from 5-byte (40-bit) to 8-byte (64-bit)
            // entropy. 64-bit is what 1Password / Google / GitHub use
            // for the same control. The display format becomes
            // xxxx-xxxx-xxxx-xxxx — same look-and-feel, 4× the
            // entropy.
            $code = $this->format(bin2hex(random_bytes(8)));
            $plain[] = $code;
            $hashed[] = Hash::make($code);
        }

        return ['plain' => $plain, 'hashed' => $hashed];
    }

    /**
     * Try to consume a recovery code. On success the matching hash is
     * removed from the user's stored list and the change is persisted.
     */
    public function consume(User $user, string $candidate): bool
    {
        $candidate = trim($candidate);
        /** @var array<int, string>|null $codes */
        $codes = $user->two_factor_recovery_codes;

        if ($codes === null || $codes === []) {
            return false;
        }

        foreach ($codes as $index => $hashedCode) {
            if (Hash::check($candidate, $hashedCode)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private function format(string $hex): string
    {
        // 16 hex chars → 4-char groups joined by dashes: aaaa-bbbb-cccc-dddd.
        return implode('-', str_split($hex, 4));
    }
}
