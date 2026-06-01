<?php

namespace App\Http\Requests\Vault;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload for POST /api/v1/vaults/{vault}/rotate-key.
 *
 * `expected_current_version` is an optimistic-concurrency token — the client
 * must pass the key_version it believes is current. If another rotation
 * landed in the meantime the server returns 409 without touching any rows.
 */
class RotateVaultKeyRequest extends FormRequest
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
            'expected_current_version' => ['required', 'integer', 'min:1'],

            'grants' => ['required', 'array', 'min:1'],
            'grants.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'grants.*.encrypted_key' => ['required', 'string', 'max:20000'],

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
