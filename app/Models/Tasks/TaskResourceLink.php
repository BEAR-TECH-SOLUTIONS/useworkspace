<?php

namespace App\Models\Tasks;

use App\Enums\TaskResourceLinkKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lightweight metadata-only reference from a TaskItem to another
 * project resource (credential / expense bucket / expense). See the
 * Task Resource Attachments spec — no ciphertext; read-time gating.
 */
class TaskResourceLink extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'task_item_id' => 'int',
        'resource_type' => TaskResourceLinkKind::class,
        'resource_id' => 'int',
        'created_by' => 'int',
        'created_at' => 'immutable_datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(TaskItem::class, 'task_item_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
