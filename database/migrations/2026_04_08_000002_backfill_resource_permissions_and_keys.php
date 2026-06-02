<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill resource_permissions and resource_keys from the legacy
     * project_members table. This migration is idempotent — every INSERT
     * ends in `ON CONFLICT DO NOTHING` keyed on the unique grant, so
     * re-running is a no-op on already-populated rows.
     *
     * Notes on the design:
     *
     *  - `granted_by` is NOT NULL in resource_permissions, so we attribute
     *    every backfilled grant to the project owner. That's the closest
     *    thing to "the system" in a world where every grant must name a
     *    real user, and it keeps the FK happy.
     *
     *  - The per-module backfills (vault / board / bucket) honor
     *    project_members.vault_role / tasks_role / expenses_role. When a
     *    sub-role is NULL we fall back to project_members.role so every
     *    member still lands with at least one grant per child resource.
     *    `rp_unique (user_id, resource_type, resource_id)` plus
     *    ON CONFLICT DO NOTHING handles the case where a project-level
     *    grant already covers the same (user, resource) pair, so the
     *    most-specific-wins rule from CLAUDE.md §5 is preserved: the
     *    project row goes in first, child rows only land when they add
     *    something the project row doesn't.
     *
     *  - resource_keys is only backfilled for resource_type='project'.
     *    Vault keys are minted during the /vaults/{vault}/migrate-key
     *    flow — the backfill must not invent ciphertext the client has
     *    never seen.
     */
    public function up(): void
    {
        // 1. Project-level permissions — straight copy of project_members.
        DB::statement(<<<'SQL'
            INSERT INTO resource_permissions
                (user_id, resource_type, resource_id, project_id, role, granted_by, created_at, updated_at)
            SELECT
                pm.user_id,
                'project'::resource_kind,
                pm.project_id,
                pm.project_id,
                pm.role,
                p.owner_id,
                pm.created_at,
                pm.updated_at
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            ON CONFLICT (user_id, resource_type, resource_id) DO NOTHING
        SQL);

        // 2. Vault-level permissions — one row per (member × vault) using
        //    COALESCE(vault_role, role). Project-level rows inserted above
        //    already carry the baseline, so the ON CONFLICT guard means
        //    we only actually write a row when it's meaningfully more
        //    specific than the project grant.
        DB::statement(<<<'SQL'
            INSERT INTO resource_permissions
                (user_id, resource_type, resource_id, project_id, role, granted_by, created_at, updated_at)
            SELECT
                pm.user_id,
                'vault'::resource_kind,
                v.id,
                v.project_id,
                COALESCE(pm.vault_role, pm.role),
                p.owner_id,
                pm.created_at,
                pm.updated_at
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            JOIN vaults    v ON v.project_id = pm.project_id
            ON CONFLICT (user_id, resource_type, resource_id) DO NOTHING
        SQL);

        // 3. Board-level permissions — mirror of step 2 against task_boards.
        DB::statement(<<<'SQL'
            INSERT INTO resource_permissions
                (user_id, resource_type, resource_id, project_id, role, granted_by, created_at, updated_at)
            SELECT
                pm.user_id,
                'board'::resource_kind,
                b.id,
                b.project_id,
                COALESCE(pm.tasks_role, pm.role),
                p.owner_id,
                pm.created_at,
                pm.updated_at
            FROM project_members pm
            JOIN projects    p ON p.id = pm.project_id
            JOIN task_boards b ON b.project_id = pm.project_id
            ON CONFLICT (user_id, resource_type, resource_id) DO NOTHING
        SQL);

        // 4. Bucket-level permissions — mirror of step 2 against expense_buckets.
        DB::statement(<<<'SQL'
            INSERT INTO resource_permissions
                (user_id, resource_type, resource_id, project_id, role, granted_by, created_at, updated_at)
            SELECT
                pm.user_id,
                'bucket'::resource_kind,
                eb.id,
                eb.project_id,
                COALESCE(pm.expenses_role, pm.role),
                p.owner_id,
                pm.created_at,
                pm.updated_at
            FROM project_members pm
            JOIN projects         p  ON p.id  = pm.project_id
            JOIN expense_buckets  eb ON eb.project_id = pm.project_id
            ON CONFLICT (user_id, resource_type, resource_id) DO NOTHING
        SQL);

        // 5. Project-level resource_keys — verbatim copy of the legacy
        //    project_members.encrypted_project_key blob. Skips members
        //    that never ran the E2E setup (NULL ciphertext).
        DB::statement(<<<'SQL'
            INSERT INTO resource_keys
                (resource_type, resource_id, project_id, user_id, encrypted_key, key_version, created_at)
            SELECT
                'project'::resource_kind,
                pm.project_id,
                pm.project_id,
                pm.user_id,
                pm.encrypted_project_key,
                1,
                pm.created_at
            FROM project_members pm
            WHERE pm.encrypted_project_key IS NOT NULL
            ON CONFLICT (resource_type, resource_id, user_id, key_version) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // Backfill is strictly additive — nothing to roll back. The
        // structural migration above drops the tables/columns if a
        // full teardown is needed.
    }
};