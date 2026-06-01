<?php

namespace App\Http\Resources\Vault;

use App\Models\Vault\Vault;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Vault
 */
class VaultResource extends JsonResource
{
    /**
     * Controllers preload each user's latest wrapped vault key via
     * PermissionService::wrappedVaultKeysFor() and stash the result on the
     * model with this attribute name. The resource simply forwards it.
     * Absent (null) for unmigrated vaults or users with no wrapped key.
     */
    public const WRAPPED_KEY_ATTR = 'my_wrapped_key';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{encrypted_key: string, key_version: int}|null $wrapped */
        $wrapped = $this->resource->getAttribute(self::WRAPPED_KEY_ATTR);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'position' => (float) $this->position,
            'is_default' => $this->is_default,
            'is_archived' => $this->is_archived,
            // `migrated_at` is the canonical "this vault is keyed"
            // signal. PermissionService::migrateVault stamps it in
            // the same transaction that writes the resource_keys
            // row, so a non-null timestamp is equivalent to "the
            // vault has at least one wrapped key on file." The
            // earlier `is_keyed` field has been dropped — it was a
            // workaround for an inconsistency that no longer
            // exists post-fix.
            'migrated_at' => $this->migrated_at?->toIso8601String(),
            'my_wrapped_key' => $wrapped,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
