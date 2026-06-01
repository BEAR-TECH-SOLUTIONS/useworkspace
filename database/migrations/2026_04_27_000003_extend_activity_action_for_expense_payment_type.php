<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds `expense_payment_type_set` to the `activity_action` Postgres
 * enum so payment-type changes can be recorded in the activity feed.
 * Same idempotent pattern as the share-link / doc-link extension
 * migrations.
 *
 * Note: a previous draft of this work also added five expense_label_*
 * values. Those were rolled back at the migration-table level but
 * remain as orphan enum labels in the type (Postgres has no
 * DROP VALUE). They're harmless — no PHP code references them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TYPE activity_action ADD VALUE IF NOT EXISTS 'expense_payment_type_set'");
    }

    public function down(): void
    {
        // Postgres has no DROP VALUE; leave the value in place.
    }
};
