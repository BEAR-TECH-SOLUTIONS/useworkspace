<?php

namespace App\Http\Requests\Vault;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for POST /api/v1/vaults/{vault}/migrate-key.
 *
 * Grants must include every authorized vault member (including the actor
 * and the project owner). Credentials must include every non-deleted
 * credential currently in the vault — the server checks set-equality in
 * PermissionService::migrateVault and returns 422 on mismatch.
 */
class MigrateVaultKeyRequest extends FormRequest
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
            'grants' => ['required', 'array', 'min:1'],
            'grants.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'grants.*.encrypted_key' => ['required', 'string', 'max:20000'],

            // Must be present so a client can't accidentally omit it. The
            // server enforces set-equality against the vault's current
            // credential set; an empty vault legitimately sends [].
            'credentials' => ['present', 'array'],
            'credentials.*.id' => ['required', 'integer'],
            'credentials.*.encrypted_data' => ['required', 'string', 'max:5000000'],
            'credentials.*.iv' => ['required', 'string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('credentials', []) as $i => $row) {
                $iv = is_array($row) ? ($row['iv'] ?? null) : null;
                if (! is_string($iv) || ! self::isValidGcmIv($iv)) {
                    $validator->errors()->add(
                        "credentials.{$i}.iv",
                        'Credential payload must use real AES-GCM encryption (12-byte iv).',
                    );
                }
            }
        });
    }

    private static function isValidGcmIv(string $iv): bool
    {
        if ($iv === '') {
            return false;
        }
        $decoded = base64_decode($iv, true);

        return $decoded !== false && strlen($decoded) === 12;
    }
}
