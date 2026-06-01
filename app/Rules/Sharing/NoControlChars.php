<?php

namespace App\Rules\Sharing;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Reject control characters in user-supplied free-text fields. Public
 * share-link `name` is rendered to recipients in a browser; control
 * chars are noise at best and a CRLF-injection vector in headers at
 * worst.
 */
class NoControlChars implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $fail("The {$attribute} contains control characters.");
        }
    }
}
