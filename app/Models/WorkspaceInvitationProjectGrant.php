<?php

namespace App\Models;

use App\Enums\MemberRole;
use App\Enums\WorkspaceInvitationGrantMode;
use App\Models\Project\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkspaceInvitationProjectGrant extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'invitation_id' => 'int',
        'project_id' => 'int',
        'mode' => WorkspaceInvitationGrantMode::class,
        'project_role' => MemberRole::class,
    ];

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(WorkspaceInvitation::class, 'invitation_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function resourceGrants(): HasMany
    {
        return $this->hasMany(WorkspaceInvitationResourceGrant::class, 'invitation_project_id');
    }

    public function vaultKeys(): HasMany
    {
        return $this->hasMany(WorkspaceInvitationVaultKey::class, 'invitation_project_id');
    }
}
