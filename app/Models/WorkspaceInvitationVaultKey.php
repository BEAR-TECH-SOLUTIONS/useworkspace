<?php

namespace App\Models;

use App\Models\Vault\Vault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staged wrapped vault key attached to a project grant on a pending
 * workspace invitation (mode='project'). On accept, each row is
 * promoted into `resource_keys`. The rotate-key cascade flips
 * `superseded_at` so admin views can warn and accept drops the row
 * with a vault_rotated warning.
 *
 * Distinct from the legacy `invitation_vault_keys` table (hanging off
 * the project-scope `invitations` table) — that one is kept alive one
 * release cycle for backward compatibility and will be removed in
 * commit B.
 */
class WorkspaceInvitationVaultKey extends Model
{
    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'invitation_project_id' => 'int',
        'vault_id' => 'int',
        'key_version' => 'int',
        'superseded_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function projectGrant(): BelongsTo
    {
        return $this->belongsTo(WorkspaceInvitationProjectGrant::class, 'invitation_project_id');
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
