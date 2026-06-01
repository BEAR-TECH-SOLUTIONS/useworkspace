<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use App\Enums\WorkspaceInvitationStatus;
use App\Models\Identity\Organisation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceInvitation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'role' => OrganisationRole::class,
        'status' => WorkspaceInvitationStatus::class,
        'expires_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'declined_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    protected $hidden = ['token'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Organisation::class, 'workspace_id');
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function projectGrants(): HasMany
    {
        return $this->hasMany(WorkspaceInvitationProjectGrant::class, 'invitation_id');
    }

    public function isPending(): bool
    {
        return $this->status === WorkspaceInvitationStatus::Pending;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
