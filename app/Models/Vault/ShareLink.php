<?php

namespace App\Models\Vault;

use App\Models\Project\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Polymorphic share link (CLAUDE.md §10 + Universal Share Links spec).
 *
 * `resource_type` + `resource_id` point at one of: board, task,
 * credential, doc, expense. The frozen JSON in `snapshot_payload` is
 * what the public endpoint returns — never re-derived on read.
 *
 * `auth_proof_hash` is set only for credential rows (zero-knowledge
 * unlock). `password_hash` is set only for non-credential
 * password-gated shares (argon2id of low-entropy human input).
 * Mutually exclusive.
 */
class ShareLink extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'snapshot_payload' => 'array',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'resource_type', 'resource_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function views(): HasMany
    {
        return $this->hasMany(ShareLinkView::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Which unlock flow the recipient must run.
     *  - 'auth_proof' → credential v2 (client derives auth_proof from password+salt)
     *  - 'password'   → non-credential password-gated (client sends plaintext, server argon2id-verifies)
     *  - 'open'       → no auth required, snapshot returned directly
     */
    public function authScheme(): string
    {
        if ($this->auth_proof_hash !== null) {
            return 'auth_proof';
        }

        if ($this->password_hash !== null) {
            return 'password';
        }

        return 'open';
    }

    public function viewsRemaining(): ?int
    {
        if ($this->max_views === null) {
            return null;
        }

        return max(0, (int) $this->max_views - (int) $this->view_count);
    }
}
