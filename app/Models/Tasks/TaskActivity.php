<?php

namespace App\Models\Tasks;

use App\Enums\ActivityAction;
use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskActivity extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'action' => ActivityAction::class,
        'meta' => 'array',
        'created_at' => 'immutable_datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskItem::class, 'task_item_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
