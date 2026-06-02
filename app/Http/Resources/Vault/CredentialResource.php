<?php

namespace App\Http\Resources\Vault;

use App\Models\Vault\Credential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Credential
 */
class CredentialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'vault_id' => $this->vault_id,
            'type' => $this->type?->value,
            'name' => $this->name,
            'url' => $this->url,
            'encrypted_data' => $this->encrypted_data,
            'iv' => $this->iv,
            'key_version' => $this->key_version,
            'tags' => $this->tags ?? [],
            'is_favorite' => $this->is_favorite,
            'is_archived' => $this->is_archived,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
