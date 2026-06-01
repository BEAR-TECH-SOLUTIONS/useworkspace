<?php

namespace App\Http\Requests\Vault;

use App\Enums\ResourceType;
use App\Models\Permissions\ResourceKey;
use App\Models\Vault\Credential;
use App\Models\Vault\Vault;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateCredentialRequest extends FormRequest
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
            'vault_id' => ['sometimes', 'nullable', 'integer', 'exists:vaults,id'],
            'name' => ['sometimes', 'string', 'max:100'],
            'url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'encrypted_data' => ['sometimes', 'string', 'max:5000000'],
            'iv' => ['sometimes', 'string', 'max:64'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:50'],
            'is_favorite' => ['sometimes', 'boolean'],
            'is_archived' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasData = $this->has('encrypted_data');
            $hasIv = $this->has('iv');

            if ($hasData !== $hasIv) {
                $validator->errors()->add('encrypted_data', 'encrypted_data and iv must be sent together.');
            }

            if ($hasIv) {
                $iv = (string) $this->input('iv');
                if (! self::isValidGcmIv($iv)) {
                    $validator->errors()->add('iv', self::E2E_IV_MESSAGE);
                }
            }

            $credential = $this->route('credential');

            // Vault move guard: any PATCH that supplies vault_id must
            // target a vault inside the credential's own project AND
            // the caller must hold `update` on that target vault.
            // Without this, anyone with `update` on the credential
            // could move it into a foreign vault by id — the IDOR
            // primitive flagged in the audit (C5).
            if ($this->has('vault_id') && $credential instanceof Credential) {
                $targetVaultId = $this->input('vault_id');
                if ($targetVaultId !== null && $targetVaultId !== '') {
                    $vault = Vault::query()->find((int) $targetVaultId);
                    if ($vault === null) {
                        $validator->errors()->add('vault_id', 'Target vault does not exist.');
                    } else {
                        if ((int) $vault->project_id !== (int) $credential->project_id) {
                            $validator->errors()->add(
                                'vault_id',
                                'Target vault must belong to the same project as the credential.',
                            );
                        }
                        if (! Gate::forUser($this->user())->allows('update', $vault)) {
                            $validator->errors()->add(
                                'vault_id',
                                'You do not have permission to move credentials into the target vault.',
                            );
                        }
                        // Canonical "is this vault keyed?" check —
                        // existence of a resource_keys row, not the
                        // `migrated_at` timestamp (the latter is just
                        // creation time per the resource_keys
                        // migration). Same correction as
                        // StoreCredentialRequest.
                        if (! self::vaultIsKeyed($vault->id)) {
                            $validator->errors()->add('vault_id', self::E2E_VAULT_NOT_KEYED_MESSAGE);
                        }
                    }
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
