<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace layer — Commit 1. `organisation_members` gets the
 * `invited_by` FK (nullable — the original admin on an auto-bootstrap
 * wasn't invited by anyone) and a backfill that guarantees every
 * organisation has a membership row for its owner with role='admin'.
 * This is the invariant the spec's §7 bullet "Every existing user has
 * exactly one workspace post-migration, with themselves as the single
 * admin" depends on.
 *
 * In practice PersonalProjectFactory already inserts this row on
 * register, so the backfill is belt-and-braces — but it's cheap and
 * any future hand-rolled org insert won't silently skip it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisation_members', function (Blueprint $table): void {
            $table->foreignId('invited_by')->nullable()->after('user_id')->constrained('users');
        });

        // Insert an admin membership row for any owner missing one.
        // Uses the owner_id column on organisations, NOT the implicit
        // Owner abstraction — workspace membership is about identity
        // (billing / directory), not about project access.
        DB::statement(<<<'SQL'
            INSERT INTO organisation_members (organisation_id, user_id, role, joined_at, created_at, updated_at)
            SELECT o.id, o.owner_id, 'admin', o.created_at, now(), now()
              FROM organisations o
             WHERE NOT EXISTS (
                SELECT 1 FROM organisation_members m
                 WHERE m.organisation_id = o.id
                   AND m.user_id = o.owner_id
             )
        SQL);

        // Owners who already had a membership row at role='member' get
        // promoted — the spec treats the original organisation owner as
        // the workspace admin, full stop.
        DB::statement(<<<'SQL'
            UPDATE organisation_members m
               SET role = 'admin'
              FROM organisations o
             WHERE m.organisation_id = o.id
               AND m.user_id = o.owner_id
               AND m.role <> 'admin'
        SQL);
    }

    public function down(): void
    {
        Schema::table('organisation_members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
        });
    }
};
