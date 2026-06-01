<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staging area for wrapped vault keys attached to a pending invitation.
 * The inviter's client wraps each migrated vault's key under the
 * invitee's RSA public key at invite-creation time; on accept the rows
 * are promoted into `resource_keys` (same transaction as the project
 * member row) and then deleted. Cascades on invitation delete so cancel
 * cleans up automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitation_vault_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->constrained('invitations')->cascadeOnDelete();
            $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->unsignedInteger('key_version');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invitation_id', 'vault_id']);
            $table->index('vault_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitation_vault_keys');
    }
};
