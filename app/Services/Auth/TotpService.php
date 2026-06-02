<?php

namespace App\Services\Auth;

/**
 * Inline RFC 6238 TOTP (Google Authenticator / 1Password / Authy compatible).
 *
 * SHA-1, 6-digit codes, 30-second step, ±1 window tolerance. Avoids pulling
 * in `pragmarx/google2fa` for what amounts to 40 lines of code.
 */
class TotpService
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    public const WINDOW = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a fresh 20-byte (160-bit) secret encoded as base32 for use
     * in TOTP provisioning URIs.
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Build an otpauth:// provisioning URI suitable for QR encoding.
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);

        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verify a user-supplied code against the secret. The ±WINDOW tolerance
     * means a code is valid for up to PERIOD seconds on either side, which
     * absorbs a little clock drift without materially widening the attack
     * surface.
     */
    public function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        return $this->verifyStep($secret, $code, $timestamp) !== null;
    }

    /**
     * Verify the code and return the *exact* counter step that matched,
     * or null on failure. Callers persist the step so a code cannot be
     * replayed inside its 30-second validity (audit H7 replay guard).
     *
     * @return int|null  the RFC 6238 counter (intdiv(time, PERIOD)) that
     *                   produced the matching code
     */
    public function verifyStep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code === null || strlen($code) !== self::DIGITS || ! ctype_digit($code)) {
            return null;
        }

        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);

        // Match across the ±WINDOW range in a constant-time-ish way:
        // walk every offset so timing doesn't leak which step matched.
        $matched = null;
        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            $candidate = $counter + $offset;
            if (hash_equals($this->generateCode($secret, $candidate), $code)) {
                $matched = $candidate;
            }
        }

        return $matched;
    }

    private function generateCode(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $binCounter = pack('N*', 0, $counter); // 64-bit big-endian
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0xF;

        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $secret = strtoupper(trim($secret, " \t\n\r\0\x0B="));
        $bits = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                throw new \InvalidArgumentException('Invalid base32 character in TOTP secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
