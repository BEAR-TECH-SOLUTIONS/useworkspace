<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorChallenge extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'user_id' => 'int',
        'attempts' => 'int',
        'expires_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
