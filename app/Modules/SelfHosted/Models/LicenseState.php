<?php

namespace App\Modules\SelfHosted\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Self-hosted license cache. Singleton — always id=1. Read by every
 * LicenseGuard pass, written by:
 *   - {@see \App\Modules\SelfHosted\Console\Commands\BootLicenseCheckCommand}
 *     at container start
 *   - {@see \App\Modules\SelfHosted\Http\Middleware\LicenseGuard} on
 *     stale-cache re-verification
 *   - {@see \App\Modules\SelfHosted\Console\Commands\PhoneHomeCommand}
 *     after a successful or rejected phone-home
 */
class LicenseState extends Model
{
    protected $table = 'license_state';

    public $timestamps = false;

    // Singleton — only ever id=1, populated by trusted internal code
    // paths (BootLicenseCheckCommand, PhoneHomeCommand, LicenseGuard).
    // Opening the guard lets `updateOrCreate(['id' => 1], ...)` set
    // the primary key on insert; the previous `['id']` guarded value
    // silently stripped it and produced auto-incremented duplicates.
    protected $guarded = [];

    protected $casts = [
        'verified_payload' => 'array',
        'verified_at' => 'immutable_datetime',
        'last_phone_home_at' => 'immutable_datetime',
        'last_phone_home_ok' => 'bool',
        'updated_at' => 'immutable_datetime',
    ];

    public static function singleton(): self
    {
        $row = self::query()->find(1);
        if ($row !== null) {
            return $row;
        }

        return self::create(['id' => 1, 'token' => '']);
    }
}
