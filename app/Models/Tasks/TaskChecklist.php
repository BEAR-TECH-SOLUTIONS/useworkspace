<?php

namespace App\Models\Tasks;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskChecklist extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_checked' => 'bool',
        'position' => 'float',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskItem::class, 'task_item_id');
    }
}
