<?php

namespace App\Http\Requests\Sharing;

use App\Rules\Sharing\Argon2idPasswordHash;
use App\Rules\Sharing\Base64BytesLength;
use App\Rules\Sharing\NoControlChars;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Polymorphic share-link create request.
 *
 * For credential shares the client also supplies `auth_proof` (32 bytes)
 * + `key_salt` (16 bytes) + `encrypted_blob` + `blob_iv` (12 bytes).
 * The server stores `auth_proof_hash = sha256(auth_proof)` — argon2 is
 * deliberately not used here because `auth_proof` is high-entropy
 * already (HKDF output). Plan §4-5.
 *
 * For non-credential shares the client may supply a pre-hashed
 * argon2id `password_hash` (driver `share_link_argon`), or nothing at
 * all (open share).
 */
class StoreShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Per-resource share authorisation runs in the controller after
        // we've resolved the source resource. Authorize() returning true
        // here only attests "the user is authenticated"; real gating is
        // Gate::authorize('share', $resource) in the controller.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resource_type' => ['required', Rule::in(['board', 'task', 'credential', 'doc', 'expense'])],
            'resource_id' => ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:200', new NoControlChars],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'max_views' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'token_hash' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],

            // Non-credential password flow: client argon2id-hashes a
            // human passphrase, ships the hash. Server never sees plain.
            // Strict shape + cost check (audit H11) so a client cannot
            // submit a cheap bcrypt/argon2i hash that crumbles offline.
            'password_hash' => ['nullable', 'string', 'max:255', new Argon2idPasswordHash],

            // Credential v2 zero-knowledge fields.
            'auth_proof' => ['nullable', 'string', new Base64BytesLength(32)],
            'key_salt' => ['nullable', 'string', 'required_with:auth_proof', new Base64BytesLength(16)],
            'encrypted_blob' => ['nullable', 'string', 'max:5242880'],
            'blob_iv' => ['nullable', 'string', new Base64BytesLength(12)],

            // Server builds snapshots for non-credential resources.
            'snapshot_payload' => ['prohibited'],

            // Optional progress-stats block, board-only.
            'include_stats' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator): void {
            $type = $this->input('resource_type');

            if ($type === 'credential') {
                if (! $this->filled('auth_proof')) {
                    $validator->errors()->add('auth_proof', 'credential_share_requires_auth');
                }
                if (! $this->filled('encrypted_blob')) {
                    $validator->errors()->add('encrypted_blob', 'Credential shares require encrypted_blob.');
                }
                if (! $this->filled('blob_iv')) {
                    $validator->errors()->add('blob_iv', 'Credential shares require blob_iv.');
                }
                if ($this->filled('password_hash')) {
                    $validator->errors()->add('password_hash', 'Credential shares use auth_proof, not password_hash.');
                }
            } else {
                if ($this->filled('auth_proof') || $this->filled('encrypted_blob') || $this->filled('blob_iv') || $this->filled('key_salt')) {
                    $validator->errors()->add('auth_proof', 'auth_proof flow is for credential shares only.');
                }
            }

            // Progress-stats block is board-only. Reject other types
            // explicitly so a typo doesn't silently get dropped.
            if ($this->boolean('include_stats') && $type !== 'board') {
                $validator->errors()->add('include_stats', 'include_stats_unsupported_for_resource');
            }
        });
    }
}
