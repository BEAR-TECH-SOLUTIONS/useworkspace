<?php

namespace App\Models\Vault;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CredentialHistory extends Model
{
    public $timestamps = false;

    protected $table = 'credential_history';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'immutable_datetime',
    ];

    public function credential(): BelongsTo
    {
        return $this->belongsTo(Credential::class);
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
