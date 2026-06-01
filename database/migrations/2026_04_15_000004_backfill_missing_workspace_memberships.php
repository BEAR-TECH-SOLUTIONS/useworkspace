<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Belt-and-braces backfill for the "new user has no workspace
 * directory row" bug. Commit 1 already ran an equivalent invariant
 * check on `organisation_members`; this re-runs it to catch any row
 * created between that deploy and now via a code path that skipped
 * the factory (seeders, one-off admin scripts, a registration handler
 * variant that wasn't updated).
 *
 * Idempotent — the NOT EXISTS clause is the guard. `invited_by` is
 * set to the owner's own id because this represents self-bootstrap,
 * matching the hardened PersonalProjectFactory.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                INSERT INTO organisation_members
                    (organisation_id, user_id, role, invited_by, joined_at, created_at, updated_at)
                SELECT o.id, o.owner_id, 'admin', o.owner_id, now(), now(), now()
                  FROM organisations o
                 WHERE NOT EXISTS (
                     SELECT 1
                       FROM organisation_members om
                      WHERE om.organisation_id = o.id
                        AND om.user_id = o.owner_id
                 )
            SQL);

            // Owners whose existing row is somehow at 'member' get
            // promoted — same promotion step from commit 1's backfill.
            // Belt-and-braces: if any path has ever created a member-
            // role row for an org owner, fix it.
            DB::statement(<<<'SQL'
                UPDATE organisation_members m
                   SET role = 'admin'
                  FROM organisations o
                 WHERE m.organisation_id = o.id
                   AND m.user_id = o.owner_id
                   AND m.role <> 'admin'
            SQL);
        });
    }

    public function down(): void
    {
        // Intentionally a no-op — removing the invariant rows would
        // re-introduce the bug the migration exists to prevent.
    }
};
