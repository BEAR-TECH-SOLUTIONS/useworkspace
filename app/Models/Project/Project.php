<?php

namespace App\Models\Project;

use App\Models\Expenses\ExpenseBucket;
use App\Models\Identity\Organisation;
use App\Models\Tasks\TaskBoard;
use App\Models\User;
use App\Models\Vault\Vault;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_personal' => 'bool',
        'is_archived' => 'bool',
        'auto_archive_completed' => 'bool',
        'archive_retention_days' => 'int',
        'modules_enabled' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function originalOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'original_owner_id');
    }

    public function boards(): HasMany
    {
        return $this->hasMany(TaskBoard::class);
    }

    public function vaults(): HasMany
    {
        return $this->hasMany(Vault::class);
    }

    public function expenseBuckets(): HasMany
    {
        return $this->hasMany(ExpenseBucket::class);
    }
}
