<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `attached_doc` / `detached_doc` to the `activity_action` Postgres
 * enum so doc attachments can be logged alongside credentials and
 * expense buckets in task_activities. Non-transactional + idempotent
 * via `ADD VALUE IF NOT EXISTS` — same pattern as
 * 2026_04_15_000008_create_task_resource_links_table.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['attached_doc', 'detached_doc'] as $value) {
            DB::statement("ALTER TYPE activity_action ADD VALUE IF NOT EXISTS '{$value}'");
        }
    }

    public function down(): void
    {
        // Postgres has no DROP VALUE. Any task_activities rows using
        // these values would fail-hard on re-create, so we leave the
        // enum values in place.
    }
};
