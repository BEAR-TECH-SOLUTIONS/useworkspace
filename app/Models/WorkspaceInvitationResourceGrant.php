<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\ResourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceInvitationResourceGrant extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'invitation_project_id' => 'int',
        'resource_id' => 'int',
        'resource_type' => ResourceType::class,
        'role' => MemberRole::class,
        'key_version' => 'int',
        'superseded_at' => 'immutable_datetime',
    ];

    public function projectGrant(): BelongsTo
    {
        return $this->belongsTo(WorkspaceInvitationProjectGrant::class, 'invitation_project_id');
    }
}
