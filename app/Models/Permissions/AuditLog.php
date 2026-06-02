<?php

namespace App\Models\Permissions;

use App\Enums\ResourceType;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generic access-plane audit log (CLAUDE.md §9 covers the separate
 * per-task activity log). Written exclusively by
 * App\Services\Permissions\AuditLogger inside the same transaction as
 * the state change it describes.
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $guarded = ['id'];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'metadata' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}