<?php

namespace App\Rules\Sharing;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a client-provided argon2id hash for a share-link passphrase
 * (audit H11). Without this check a client could submit a bcrypt hash
 * with `cost=4`, or an argon2i hash with `t=1, m=1024`, which would let
 * an attacker who later stole the DB row crack the passphrase in
 * milliseconds. We enforce:
 *
 *   • PHP recognises it as PASSWORD_ARGON2ID (must start `$argon2id$`)
 *   • memory_cost ≥ 65536 (64 MiB)
 *   • time_cost   ≥ 3
 *
 * Mirrors ShareLinkPasswordHasher's own parameters so a client built
 * with the matching helper passes by construction.
 */
class Argon2idPasswordHash implements ValidationRule
{
    private const MIN_MEMORY_COST = 65536;

    private const MIN_TIME_COST = 3;

    /**
     * @param  Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            $fail('The :attribute must be a non-empty argon2id hash.');

            return;
        }

        if (! str_starts_with($value, '$argon2id$')) {
            $fail('The :attribute must be an argon2id hash.');

            return;
        }

        $info = password_get_info($value);
        if (($info['algoName'] ?? null) !== 'argon2id') {
            $fail('The :attribute must be an argon2id hash.');

            return;
        }

        $opts = (array) ($info['options'] ?? []);
        $memoryCost = (int) ($opts['memory_cost'] ?? 0);
        $timeCost = (int) ($opts['time_cost'] ?? 0);

        if ($memoryCost < self::MIN_MEMORY_COST) {
            $fail('The :attribute hash uses memory_cost below the minimum of '.self::MIN_MEMORY_COST.'.');

            return;
        }

        if ($timeCost < self::MIN_TIME_COST) {
            $fail('The :attribute hash uses time_cost below the minimum of '.self::MIN_TIME_COST.'.');
        }
    }
}
