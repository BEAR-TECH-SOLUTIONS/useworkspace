<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `vaults.migrated_at` for rows that have a keyed plane
 * (`resource_keys` row exists) but a NULL timestamp. The bug:
 * `PermissionService::migrateVault` wrote the `resource_keys` row
 * + the `vault.migrated` audit log inside a transaction but did
 * not stamp `vaults.migrated_at`, so any vault migrated before
 * the companion code fix was deployed sits with a NULL timestamp
 * forever — which the desktop client renders as "needs upgrade"
 * and refuses to share.
 *
 * Coalesces with `created_at` so the timestamp value is at worst
 * a reasonable estimate (the vault was created at that time and
 * is now known to be keyed). We don't have the actual migrate-key
 * timestamp post-hoc; `created_at` is the closest non-NULL
 * anchor and matches what the migration that introduced
 * `vaults.migrated_at` promised via its DEFAULT now() ("born
 * migrated").
 *
 * Idempotent — operates only on rows that satisfy both conditions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE vaults
               SET migrated_at = COALESCE(migrated_at, created_at)
             WHERE migrated_at IS NULL
               AND EXISTS (
                   SELECT 1
                     FROM resource_keys rk
                    WHERE rk.resource_type = 'vault'
                      AND rk.resource_id = vaults.id
               )
        SQL);
    }

    public function down(): void
    {
        // No safe reverse. The pre-backfill state was the bug we
        // just fixed; restoring it would re-trigger the "needs
        // upgrade" UI for already-keyed vaults.
    }
};
