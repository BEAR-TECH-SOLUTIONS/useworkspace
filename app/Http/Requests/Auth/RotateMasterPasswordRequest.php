<?php

namespace App\Http\Requests\Auth;

use App\Rules\Auth\CurrentPassword;
use App\Rules\Sharing\Base64BytesLength;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a master-password ROTATION bundle.
 *
 * Distinct from {@see SetupMasterPasswordRequest} in two ways:
 *   • `public_key` is intentionally absent. The rotation only
 *     re-wraps the existing RSA private key under a new password +
 *     salt; the public key must stay byte-identical so the user's
 *     vault grants (`resource_keys` rows wrapped to the public key)
 *     continue to decrypt. Allowing a public_key field here would
 *     create a footgun that silently orphans every wrapped key.
 *   • `current_password` is required. The server cannot verify the
 *     OLD master password (the zero-knowledge contract — the client
 *     proves possession by being able to decrypt the existing
 *     private-key bundle). A stolen Sanctum token could therefore
 *     PUT a garbage bundle and permanently lock the legitimate
 *     user out. Requiring the ACCOUNT password as a second factor
 *     means a stolen bearer alone is insufficient — mirrors the
 *     audit-H8 pattern on DELETE /auth/2fa and POST /auth/2fa/verify.
 */
class RotateMasterPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Fixed-byte fields — width matches the client crypto
            // contract exactly. Bad width here silently breaks the
            // wrap/unwrap derivation.
            'master_password_salt' => ['required', 'string', new Base64BytesLength(16)],
            'private_key_iv' => ['required', 'string', new Base64BytesLength(12)],

            // Variable-length opaque ciphertext / verifier. Validate
            // base64 shape + a generous upper bound so a malformed
            // body fails fast instead of landing as garbage in the
            // user row.
            'master_password_verifier' => ['required', 'string', 'max:1024', self::base64String()],
            'encrypted_private_key' => ['required', 'string', 'max:32768', self::base64String()],

            // DoS / lock-out hardening (audit H8). Caught here so a
            // stolen bearer alone cannot rewrite the bundle. Checks the
            // supplied value against the authenticated user's
            // password_hash via Hash::check.
            'current_password' => ['required', 'string', new CurrentPassword($this->user())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        // Make a wrong-public_key submission loud rather than letting
        // it through silently — the rules() omit public_key, but a
        // misinformed client might still send one. Reject it
        // explicitly so the client sees the right error code.
        $validator->after(function (Validator $v): void {
            if ($this->has('public_key')) {
                $v->errors()->add(
                    'public_key',
                    'Rotating the master password must not change the public key. Omit this field.',
                );
            }
        });
    }

    /**
     * @return Closure(string, mixed, Closure): void
     */
    private static function base64String(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || $value === '') {
                $fail("The {$attribute} must be a non-empty base64 string.");

                return;
            }

            if (base64_decode($value, true) === false) {
                $fail("The {$attribute} must be valid base64.");
            }
        };
    }
}
