<?php

namespace App\Models\Permissions;

use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeferredAccessGrant extends Model
{
    protected $table = 'deferred_access_grants';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'resources' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'workspace_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function provisioner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provisioned_by');
    }
}
