<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hardens 2FA against two adjacent attacks (audit H7, H15):
 *
 *   • last_totp_step          — the most-recently-consumed RFC 6238
 *                               counter step. Used to refuse the same
 *                               code twice inside its 30-second window
 *                               (replay guard).
 *   • two_factor_failed_attempts / two_factor_locked_until
 *                             — persisted on the user, not on the
 *                               TwoFactorChallenge row, so an attacker
 *                               cannot rotate failures across fresh
 *                               challenges to bypass the per-challenge
 *                               cap of 5 (cross-challenge counter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->bigInteger('last_totp_step')->nullable();
            $table->unsignedInteger('two_factor_failed_attempts')->default(0);
            $table->timestampTz('two_factor_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'last_totp_step',
                'two_factor_failed_attempts',
                'two_factor_locked_until',
            ]);
        });
    }
};
