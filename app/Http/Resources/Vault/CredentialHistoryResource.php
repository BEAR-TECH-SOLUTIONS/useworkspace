<?php

namespace App\Http\Resources\Vault;

use App\Models\Vault\CredentialHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CredentialHistory
 */
class CredentialHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'credential_id' => $this->credential_id,
            'changed_by' => $this->changed_by,
            'encrypted_data' => $this->encrypted_data,
            'iv' => $this->iv,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
