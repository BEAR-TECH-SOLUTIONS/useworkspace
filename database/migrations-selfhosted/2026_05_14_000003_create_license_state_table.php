<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-hosted edition only. Caches the most recently verified license
 * payload + the last-known phone-home result so authenticated
 * requests can gate access without a network call and without
 * re-verifying the Ed25519 signature on every middleware pass.
 *
 * Singleton row enforced by `id = 1` — there's only ever one license
 * per instance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_state', function (Blueprint $table): void {
            $table->id();
            $table->text('token');                              // current LICENSE_TOKEN value
            $table->jsonb('verified_payload')->nullable();      // last successful local verify
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('last_phone_home_at')->nullable();
            $table->boolean('last_phone_home_ok')->default(false);
            $table->string('last_phone_home_code', 64)->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_state');
    }
};
