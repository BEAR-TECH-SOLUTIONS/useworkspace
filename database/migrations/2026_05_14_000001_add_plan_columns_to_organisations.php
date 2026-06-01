<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the supporting plan-lifecycle columns: per-workspace cap
 * overrides (`plan_limits`), billing-period bookkeeping
 * (`plan_started_at`, `plan_renews_at`) and a denormalised
 * `member_count` so the workspace resource doesn't need a join+count
 * per request.
 *
 * The plan/tier *name* itself lives in `organisations.tier`
 * (workspace_tier enum, see the earlier migration) — there is no
 * separate `plan` column. PlanEnforcer reads it directly.
 *
 * `member_count` stays in sync via OrganisationMemberObserver on
 * every member create/delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->jsonb('plan_limits')->nullable()->after('billing_status');
            $table->timestampTz('plan_started_at')->nullable()->after('plan_limits');
            $table->timestampTz('plan_renews_at')->nullable()->after('plan_started_at');
            $table->unsignedInteger('member_count')->default(0)->after('plan_renews_at');
        });

        // Backfill member_count for existing workspaces from the live
        // membership table — defaults to 0 above, but existing rows
        // already have members we must reflect.
        DB::statement('
            UPDATE organisations
               SET member_count = sub.cnt
              FROM (SELECT organisation_id, COUNT(*) AS cnt
                      FROM organisation_members
                  GROUP BY organisation_id) AS sub
             WHERE sub.organisation_id = organisations.id
        ');
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['plan_limits', 'plan_started_at', 'plan_renews_at', 'member_count']);
        });
    }
};
