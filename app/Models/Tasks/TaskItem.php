<?php

namespace App\Models\Tasks;

use App\Enums\TaskPriority;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'priority' => TaskPriority::class,
        'position' => 'float',
        'due_date' => 'immutable_date',
        'is_completed' => 'bool',
        'is_archived' => 'bool',
        'archived_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(TaskColumn::class, 'column_id');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(TaskChecklist::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(TaskLabel::class, 'task_item_labels', 'task_item_id', 'label_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_assignees', 'task_item_id', 'user_id');
    }

    public function resourceLinks(): HasMany
    {
        return $this->hasMany(TaskResourceLink::class, 'task_item_id');
    }
}
