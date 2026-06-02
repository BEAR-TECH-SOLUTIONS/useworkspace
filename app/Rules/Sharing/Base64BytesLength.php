<?php

namespace App\Rules\Sharing;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a base64-decoded payload is exactly $bytes long.
 *
 * The crypto contract (auth_proof = 32, blob_iv = 12, key_salt = 16)
 * relies on these widths — accepting anything else lets the client
 * silently break the unlock derivation.
 */
class Base64BytesLength implements ValidationRule
{
    public function __construct(private readonly int $bytes) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail("The {$attribute} must be a base64 string.");

            return;
        }

        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            $fail("The {$attribute} must be valid base64.");

            return;
        }

        if (strlen($decoded) !== $this->bytes) {
            $fail("The {$attribute} must decode to exactly {$this->bytes} bytes.");
        }
    }
}
