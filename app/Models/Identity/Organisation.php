<?php

namespace App\Models\Identity;

use App\Enums\PlanTier;
use App\Enums\WorkspaceBillingStatus;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_personal' => 'bool',
        // `tier` is the single canonical billing dimension. Stored
        // as the `workspace_tier` Postgres enum; cast to PlanTier
        // (same vocabulary).
        'tier' => PlanTier::class,
        'plan_limits' => 'array',
        'plan_started_at' => 'immutable_datetime',
        'plan_renews_at' => 'immutable_datetime',
        'cancel_scheduled_at' => 'immutable_datetime',
        'member_count' => 'int',
        'members_can_create_projects' => 'bool',
        'members_can_invite_members' => 'bool',
        'billing_status' => WorkspaceBillingStatus::class,
        'seat_cap' => 'int',
        'trial_ends_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(OrganisationMember::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
