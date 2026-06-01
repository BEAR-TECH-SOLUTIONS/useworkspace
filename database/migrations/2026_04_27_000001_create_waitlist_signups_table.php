<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public landing-page waitlist. CITEXT email + unique index gives us
 * case-insensitive idempotency without ever returning "already exists"
 * to the caller — duplicates are swallowed at the DB layer so an
 * attacker can't enumerate registered addresses through the API.
 *
 * `confirmed_at` is reserved for a future double-opt-in flow; we ship
 * single-opt-in and let the email column carry the trust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->charset('citext');
            $table->string('source', 64)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->text('ip_hash')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('email');
            $table->index('source');
            $table->index('created_at');
        });

        // Enforce CITEXT type at the DB level — Laravel's $table->string()
        // creates VARCHAR by default; a partial-index UNIQUE on a plain
        // string would let "Foo@Bar" and "foo@bar" coexist. Convert in
        // place so the unique index inherits citext collation.
        DB::statement('ALTER TABLE waitlist_signups ALTER COLUMN email TYPE CITEXT');
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }
};
