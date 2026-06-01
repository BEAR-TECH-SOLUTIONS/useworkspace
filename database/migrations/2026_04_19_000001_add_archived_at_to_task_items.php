<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archived-tasks browsing spec: add `archived_at` so the archived list
 * can be sorted deterministically by "when it was archived" rather
 * than piggy-backing on `updated_at` (which also moves on edits while
 * a task is archived). Existing archived rows are backfilled from
 * `updated_at` as the closest available proxy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_items', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('is_archived');
        });

        // Partial index powers the board's archived-tasks list. `archived_at DESC`
        // is the natural sort for the endpoint and an index lets cursor pagination
        // stay O(log n) even on boards with thousands of archived tasks.
        DB::statement('CREATE INDEX task_items_column_archived_at_idx ON task_items (column_id, archived_at DESC) WHERE is_archived = true');

        DB::statement('UPDATE task_items SET archived_at = updated_at WHERE is_archived = true AND archived_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS task_items_column_archived_at_idx');

        Schema::table('task_items', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
