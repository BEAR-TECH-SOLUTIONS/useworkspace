<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace layer — Commit 1. The `organisations` table is being
 * promoted into a first-class workspace: billing unit, member
 * directory, container for projects. DB table name stays
 * `organisations` for FK stability; the API surface renames it to
 * `workspaces`. See spec §2.1.
 *
 * seat_cap is materialised (not recomputed on every read) so directory
 * queries can return it in a single round-trip. It stays in sync with
 * `tier` via the billing webhook (commit 4) and the seat-cap-on-
 * downgrade guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `workspace_tier` is the canonical billing dimension used by
        // PlanEnforcer (cloud) / LicenseEnforcer (self-hosted) and
        // surfaced unchanged in GET /api/v1/plans + on every workspace
        // response. The enum mirrors App\Enums\PlanTier.
        DB::statement("CREATE TYPE workspace_tier AS ENUM ('free', 'entrepreneur', 'team', 'self_hosted')");
        DB::statement("CREATE TYPE workspace_billing_status AS ENUM ('active', 'past_due', 'canceled', 'trialing')");

        Schema::table('organisations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('seat_cap')->default(1)->after('is_personal');
            $table->string('billing_customer_id')->nullable()->after('seat_cap');
            $table->string('billing_subscription_id')->nullable()->after('billing_customer_id');
            $table->timestamp('trial_ends_at')->nullable()->after('billing_subscription_id');
        });

        DB::statement("ALTER TABLE organisations ADD COLUMN tier workspace_tier NOT NULL DEFAULT 'free'");
        DB::statement('ALTER TABLE organisations ADD COLUMN billing_status workspace_billing_status NULL');

        // All existing orgs are tier=free → seat_cap=1. Explicit so
        // future rows that bypass the default still match the invariant.
        DB::statement("UPDATE organisations SET seat_cap = 1 WHERE tier = 'free'");
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn([
                'seat_cap',
                'billing_customer_id',
                'billing_subscription_id',
                'trial_ends_at',
            ]);
        });

        DB::statement('ALTER TABLE organisations DROP COLUMN IF EXISTS tier');
        DB::statement('ALTER TABLE organisations DROP COLUMN IF EXISTS billing_status');

        DB::statement('DROP TYPE IF EXISTS workspace_billing_status');
        DB::statement('DROP TYPE IF EXISTS workspace_tier');
    }
};
