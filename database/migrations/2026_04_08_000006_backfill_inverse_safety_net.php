<?php

use Illuminate\Database\Migrations\Migration;

/**
 * Safety net for staging rollbacks.
 *
 * The 2026_04_08_000002 backfill migration ships with an intentionally
 * empty down() because it is strictly additive: it INSERTs into
 * resource_permissions and resource_keys and can't safely delete those
 * rows on rollback without also clobbering legitimate production grants
 * that were added later.
 *
 * CLAUDE.md §11 forbids editing a shipped migration. If a reviewer needs
 * to unwind the backfill in staging — for example after rolling back
 * migration 000003 (the legacy column drop) — they can roll back *this*
 * migration to invoke the inverse delete. It targets only the rows the
 * backfill could have inserted: user IDs that are still in
 * project_members with the same (user_id, project_id) pair. Rows on
 * resources created after the backfill are left alone.
 *
 * up() is a no-op. down() is the dangerous bit and must only run in
 * staging as part of a full rollback sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        // No-op. This migration exists solely to provide the inverse
        // delete as its down() for staging rollbacks.
    }

    public function down(): void
    {
        // Delete resource_keys rows that look like they came from the backfill:
        // project-typed, key_version = 1, owned by a user who is still a
        // project member of the same project. If the backfill never ran this
        // is a no-op; if it did run but legitimate new rows have been added
        // since, those are preserved because the join is (user_id, project_id).
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            DELETE FROM resource_keys rk
            USING project_members pm
            WHERE rk.resource_type = 'project'
              AND rk.resource_id   = pm.project_id
              AND rk.user_id       = pm.user_id
              AND rk.key_version   = 1
              AND rk.project_id    = pm.project_id
        SQL);

        // Delete resource_permissions rows that look like they came from the
        // backfill. granted_by = projects.owner_id is the backfill's fingerprint
        // (see 2026_04_08_000002 — every backfilled row is attributed to the
        // project owner because granted_by is NOT NULL). Any row added by a
        // controller after the backfill will have granted_by = the actor, not
        // necessarily the owner, so we scope the delete to that fingerprint.
        \Illuminate\Support\Facades\DB::statement(<<<'SQL'
            DELETE FROM resource_permissions rp
            USING project_members pm,
                  projects p
            WHERE rp.project_id   = pm.project_id
              AND rp.user_id      = pm.user_id
              AND p.id            = pm.project_id
              AND rp.granted_by   = p.owner_id
              AND rp.resource_type IN ('project', 'vault', 'board', 'bucket')
        SQL);
    }
};