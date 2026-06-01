<?php

namespace App\Http\Requests\Waitlist;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a public waitlist signup.
 *
 * Email validation is RFC-only — we deliberately do NOT do a DNS MX
 * lookup. DNS-validated email rules are slow (every signup hits a
 * recursive resolver), flaky in CI, and reject legitimate corporate
 * setups whose MX records aren't visible from our edge. Spam pressure
 * is absorbed by the per-IP rate limiter and the honeypot field
 * instead. A typo'd domain just bounces when we later try to email
 * the user — acceptable failure mode for a waitlist.
 *
 * `website` is the honeypot — should always be empty for real users.
 * Bots fill any input-shaped field. A filled honeypot is treated by
 * the controller as a successful no-op so the bot can't distinguish
 * acceptance from silent rejection.
 */
class StoreWaitlistRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:254'],
            'source' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'metadata' => ['nullable', 'array'],
            'metadata.*' => ['nullable', 'string', 'max:512'],

            // Honeypot — must be empty for the request to count.
            // Bots filling it get a happy-path 201 (see controller).
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function isHoneypotTripped(): bool
    {
        return $this->filled('website');
    }
}
