<?php

namespace App\Rules\Auth;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * Validates that the supplied value is the authenticated user's current
 * ACCOUNT password.
 *
 * Replaces Laravel's built-in `current_password` rule, which resolves
 * the user via the DEFAULT auth guard (here `web`/session). These
 * endpoints authenticate with Sanctum bearer tokens, so the web guard
 * is always a guest on them and the built-in rule would reject every
 * request. Checking the request's already-authenticated user directly
 * is both guard-correct and column-correct
 * (User::getAuthPassword() → password_hash).
 *
 * Pass the request user in: `new CurrentPassword($this->user())`.
 */
class CurrentPassword implements ValidationRule
{
    public function __construct(private readonly ?Authenticatable $user) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->user === null
            || ! Hash::check((string) $value, (string) $this->user->getAuthPassword())) {
            $fail('The provided password is incorrect.');
        }
    }
}
