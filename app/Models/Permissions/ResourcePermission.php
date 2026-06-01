<?php

namespace App\Models\Permissions;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourcePermission extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'role' => MemberRole::class,
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'resource_type', 'resource_id');
    }
}
