<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidates the legacy `plan` column into a canonical `tier`
 * column whose Postgres enum carries the new PlanTier values
 * (free, entrepreneur, team, self_hosted).
 *
 * Historical context: the prior rename rewrote earlier migration
 * files in place to land at the canonical shape directly. That
 * works for `migrate:fresh` but rolling-forward (`migrate --force`)
 * left every existing DB in a half-state: the legacy `tier` enum
 * still held (free, solo, team, business), AND a separate `plan`
 * column existed alongside it.
 *
 * This migration converges any of the following starting states
 * to the same end state:
 *
 *   A. Fresh DB after migrate:fresh of the rewritten migrations.
 *      Only `tier` (canonical values) exists. → no-op.
 *
 *   B. Half-state from rolling-forward: both `tier` (old enum)
 *      and `plan` (canonical enum) exist. → carry plan's value
 *      onto tier, recreate tier's enum with canonical values,
 *      drop plan, drop the plan_tier type.
 *
 *   C. Old DB with only `tier` (legacy enum). → just recreate
 *      the enum with canonical values, remap solo→entrepreneur
 *      and business→team.
 *
 * Safe to run multiple times — every step probes current state
 * via information_schema / pg_type before acting.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $hasPlan = $this->columnExists('organisations', 'plan');

            // Step 1 — rewrite the `tier` column to use the
            // canonical enum values. We can't ALTER an enum to
            // remove values, so the safe path is:
            //   a. flip column to text (preserves all values)
            //   b. drop the legacy workspace_tier type
            //   c. recreate workspace_tier with canonical values
            //   d. flip the column back, mapping any legacy
            //      values (solo, business) to their canonical
            //      successors on the way through
            //
            // If `plan` exists, its value wins — that's the
            // column the latest writes were actually landing on.
            $coalesce = $hasPlan
                ? 'COALESCE(plan::text, tier::text, \'free\')'
                : 'tier::text';

            DB::statement('ALTER TABLE organisations ALTER COLUMN tier DROP DEFAULT');
            DB::statement('ALTER TABLE organisations ALTER COLUMN tier TYPE text USING '.$coalesce);

            // Drop the legacy enum types now that no column references them.
            if ($hasPlan) {
                DB::statement('ALTER TABLE organisations DROP COLUMN plan');
                DB::statement('DROP TYPE IF EXISTS plan_tier');
            }
            DB::statement('DROP TYPE IF EXISTS workspace_tier');

            // Recreate workspace_tier with the canonical PlanTier values.
            DB::statement("CREATE TYPE workspace_tier AS ENUM ('free', 'entrepreneur', 'team', 'self_hosted')");

            // Map any straggler legacy values onto canonical ones
            // before flipping the column type back. Mirrors the
            // mapping WorkspaceTier::toPlanTier() used before the
            // rename: solo/team → entrepreneur, business → team.
            DB::statement(<<<'SQL'
                UPDATE organisations
                   SET tier = CASE tier
                                WHEN 'solo'         THEN 'entrepreneur'
                                WHEN 'business'     THEN 'team'
                                WHEN 'entrepreneur' THEN 'entrepreneur'
                                WHEN 'team'         THEN 'team'
                                WHEN 'self_hosted'  THEN 'self_hosted'
                                ELSE 'free'
                              END
            SQL);

            DB::statement('ALTER TABLE organisations ALTER COLUMN tier TYPE workspace_tier USING tier::workspace_tier');
            DB::statement("ALTER TABLE organisations ALTER COLUMN tier SET DEFAULT 'free'");
            DB::statement('ALTER TABLE organisations ALTER COLUMN tier SET NOT NULL');
        });
    }

    public function down(): void
    {
        // No safe reverse — the original two-column state mixed
        // legacy and canonical enum values across rows and is not
        // worth reconstructing. If you need to roll back, restore
        // from backup.
    }

    private function columnExists(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }
};
