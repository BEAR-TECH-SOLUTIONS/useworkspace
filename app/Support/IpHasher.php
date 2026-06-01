<?php

namespace App\Support;

/**
 * One-way IP hashing with a server-side pepper (audit M6).
 *
 * Without a pepper a raw `sha256(ip)` is trivially reversible: there
 * are only 2^32 IPv4 addresses, so an attacker who steals the DB can
 * pre-compute the whole table in seconds. Mixing in a server-side
 * secret (`TC_IP_HASH_PEPPER`) means a DB leak alone leaks nothing —
 * the attacker also needs the runtime config.
 *
 * Falls back to the application key when the dedicated pepper isn't
 * set so a missing env var doesn't silently downgrade to unsalted.
 */
class IpHasher
{
    public static function hash(?string $ip): string
    {
        $ip = (string) $ip;
        if ($ip === '') {
            return '';
        }

        return hash_hmac('sha256', $ip, self::pepper());
    }

    private static function pepper(): string
    {
        $pepper = (string) config('teamcore.ip_hash_pepper', '');
        if ($pepper !== '') {
            return $pepper;
        }

        // Strip the cipher prefix from APP_KEY so we don't accidentally
        // mix the prefix string with the random material.
        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = substr($appKey, 7);
        }

        return $appKey === '' ? 'fallback-ip-pepper' : $appKey;
    }
}
