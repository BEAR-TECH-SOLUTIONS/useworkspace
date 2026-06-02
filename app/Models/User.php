<?php

namespace App\Models;

use App\Models\Identity\Organisation;
use App\Models\Project\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = [
        'password_hash',
        'remember_token',
        'master_password_salt',
        'master_password_verifier',
        'encrypted_private_key',
        'private_key_iv',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'last_totp_step',
        'two_factor_failed_attempts',
        'two_factor_locked_until',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * True once the client has uploaded the full master-password crypto bundle
     * via POST /api/v1/auth/master-password. Until this returns true the user
     * cannot use any of the vault/task/expense endpoints — see the
     * EnsureMasterPasswordSet middleware for enforcement.
     */
    public function hasMasterPassword(): bool
    {
        return $this->master_password_salt !== null
            && $this->master_password_verifier !== null
            && $this->public_key !== null
            && $this->encrypted_private_key !== null
            && $this->private_key_iv !== null;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'two_factor_enabled' => 'bool',
            'two_factor_confirmed_at' => 'immutable_datetime',
            // Secret is stored Laravel-encrypted so a DB leak alone cannot
            // forge TOTP codes.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'last_totp_step' => 'integer',
            'two_factor_failed_attempts' => 'integer',
            'two_factor_locked_until' => 'immutable_datetime',
            'is_admin' => 'bool',
        ];
    }

    public function organisations(): HasMany
    {
        return $this->hasMany(Organisation::class, 'owner_id');
    }

    public function ownedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'owner_id');
    }
}
