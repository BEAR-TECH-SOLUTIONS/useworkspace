<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // Use CITEXT for case-insensitive unique email.
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password_hash');

            // Master password (§6) — server only stores salt + verifier.
            $table->text('master_password_salt')->nullable();
            $table->text('master_password_verifier')->nullable();

            // E2E vault keypair — server stores public key + wrapped private key only.
            $table->text('public_key')->nullable();
            $table->text('encrypted_private_key')->nullable();
            $table->text('private_key_iv')->nullable();

            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        // Switch email column to CITEXT and add unique index.
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE CITEXT');
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
