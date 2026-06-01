<?php

namespace App\Models\Vault;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShareLinkView extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'viewed_at' => 'immutable_datetime',
    ];

    public function shareLink(): BelongsTo
    {
        return $this->belongsTo(ShareLink::class);
    }
}
