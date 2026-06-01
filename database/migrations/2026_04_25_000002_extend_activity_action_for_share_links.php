<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the universal-share-link lifecycle values to the
 * `activity_action` Postgres enum so task/board share events can be
 * recorded in `task_activities`. Mirrors the
 * 2026_04_22_000001_extend_activity_action_for_doc_links pattern:
 * non-transactional, idempotent via ADD VALUE IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        $values = [
            'task_shared',
            'task_share_revoked',
            'task_share_viewed',
            'board_shared',
            'board_share_revoked',
            'board_share_viewed',
        ];

        foreach ($values as $value) {
            DB::statement("ALTER TYPE activity_action ADD VALUE IF NOT EXISTS '{$value}'");
        }
    }

    public function down(): void
    {
        // Postgres has no DROP VALUE; leave the enum values in place.
    }
};
