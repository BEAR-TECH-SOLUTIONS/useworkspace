<?php

namespace App\Http\Requests\Vault;

use App\Enums\EntryType;
use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Vault\Vault;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialRequest extends FormRequest
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
            'vault_id' => ['nullable', 'integer', 'exists:vaults,id'],
            'type' => ['required', Rule::enum(EntryType::class)],
            'name' => ['required', 'string', 'max:100'],
            'url' => ['nullable', 'string', 'max:500'],
            // Server only ever sees ciphertext + iv. We bound it to ~5 MB to keep
            // pathological payloads from blowing up the API tier — that's already
            // far larger than any real credential blob.
            'encrypted_data' => ['required', 'string', 'max:5000000'],
            'iv' => ['required', 'string', 'max:64'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $iv = $this->input('iv');
            if (is_string($iv) && ! self::isValidGcmIv($iv)) {
                $validator->errors()->add('iv', self::E2E_IV_MESSAGE);
            }

            // Vault "is keyed?" check (canonical source per the
            // migration that introduced resource_keys: key existence
            // is the truth, `migrated_at` is just a creation
            // timestamp). The previous check looked at
            // `vaults.migrated_at IS NULL`, which is dead code on a
            // schema where `migrated_at` defaults to now() — that's
            // how the credentials endpoint and migrate-key endpoint
            // ended up disagreeing on "is this vault migrated".
            $vaultId = $this->input('vault_id');
            if ($vaultId !== null && $vaultId !== '') {
                $vault = Vault::query()->find((int) $vaultId);
                if ($vault !== null && ! self::vaultIsKeyed($vault->id)) {
                    $validator->errors()->add('vault_id', self::E2E_VAULT_NOT_KEYED_MESSAGE);
                }
            }
        });
    }

    private const E2E_IV_MESSAGE = 'Credential payload must use real AES-GCM encryption (12-byte iv).';

    private const E2E_VAULT_NOT_KEYED_MESSAGE = 'Vault has not been keyed yet — call POST /vaults/{vault}/migrate-key first.';

    private static function vaultIsKeyed(int $vaultId): bool
    {
        return ResourceKey::query()
            ->for(ResourceType::Vault, $vaultId)
            ->exists();
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
