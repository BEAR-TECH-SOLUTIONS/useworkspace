<?php

namespace App\Models\Permissions;

use App\Enums\ResourceType;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wrapped symmetric key for a project or a vault, encrypted for one
 * specific recipient with their RSA public key. The server stores ciphertext
 * only — it never sees the plaintext key material.
 *
 * A DB CHECK constraint pins resource_type to 'project' or 'vault'. Any
 * attempt to insert a 'board' or 'bucket' row will be rejected by Postgres.
 */
class ResourceKey extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'key_version' => 'int',
        'created_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Query scope: keys for a single (resource_type, resource_id) pair.
     * Used by the vault migrate-key / rotate-key endpoints.
     */
    public function scopeFor(Builder $query, ResourceType $type, int $resourceId): Builder
    {
        return $query
            ->where('resource_type', $type->value)
            ->where('resource_id', $resourceId);
    }
}