<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `users.is_admin` is a Core column — read by the `cloud-admin` Gate
 * (cloud) and set by `tc:admin:create` during self-hosted install.
 * Postgres `ADD COLUMN IF NOT EXISTS` keeps the migration idempotent
 * on cloud DBs that already added the column via the now-trimmed
 * licenses migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS is_admin');
    }
};
