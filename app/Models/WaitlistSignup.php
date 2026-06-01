<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Public waitlist row. Created by an unauthenticated landing-page
 * POST. Holds nothing sensitive: email + provenance metadata.
 */
class WaitlistSignup extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'metadata' => 'array',
        'confirmed_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
