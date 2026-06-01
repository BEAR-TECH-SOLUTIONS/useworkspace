<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the workspace-owner-has-full-access invariant on every
 * existing project + vault. ProjectBootstrapper now writes these
 * grants synchronously at project creation; this migration handles
 * every project + vault that pre-dates the invariant.
 *
 * Two passes:
 *
 *   1. resource_permissions — for every project, ensure the
 *      workspace owner has a project-level Owner row. Cascades to
 *      boards / vaults / buckets / docs via the standard
 *      project-level grant rule; no per-child rows needed.
 *
 *   2. deferred_access_grants — for every vault that already has a
 *      key plane (a resource_keys row exists for it) but the
 *      workspace owner has no wrapped key, write a deferred grant
 *      so the existing /deferred-access/{id}/finalize machinery
 *      can ask a current key-holder's client to wrap the vault key
 *      for the workspace owner. The server cannot wrap keys — that
 *      is the E2E crypto contract — so this is the best we can do
 *      from the backend side.
 *
 * Idempotent: the `(user_id, resource_type, resource_id)` unique
 * index on resource_permissions, and the
 * `(user_id, project_id)` unique on deferred_access_grants, both
 * prevent duplicate rows on re-run.
 *
 * Skipped scope:
 *   • Personal workspaces are eligible too — the workspace owner
 *     IS the project owner there, so the existing
 *     project-creator grant already covers them; the
 *     ON CONFLICT DO NOTHING in pass 1 makes that a no-op.
 *   • Self-hosted does not run this — the migration is path-
 *     ambivalent but the invariant exists in code regardless of
 *     edition.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // Pass 1 — workspace-owner project-level grant.
            //
            // INSERT ... SELECT pulls every (organisation, project)
            // pair and writes the missing row in one statement.
            // ON CONFLICT DO NOTHING uses the existing
            // resource_permissions_user_resource_unique index
            // (user_id, resource_type, resource_id) so re-runs and
            // any pre-existing rows (e.g. workspace owner who is
            // also the project creator) are safely ignored.
            DB::statement(<<<'SQL'
                INSERT INTO resource_permissions
                    (user_id, resource_type, resource_id, project_id, role, granted_by, created_at, updated_at)
                SELECT
                    o.owner_id,
                    'project'::resource_kind,
                    p.id,
                    p.id,
                    'owner'::member_role,
                    o.owner_id,
                    NOW(),
                    NOW()
                FROM projects p
                JOIN organisations o ON o.id = p.organisation_id
                WHERE o.owner_id IS NOT NULL
                ON CONFLICT (user_id, resource_type, resource_id) DO NOTHING
            SQL);

            // Pass 2 — deferred grants for already-keyed vaults
            // where the workspace owner has no wrapped key yet.
            //
            // Group by (workspace, project, owner) and aggregate
            // every qualifying vault into the resources JSONB,
            // matching the shape WorkspaceProvisioningService
            // emits (`[{"vault_id": …}, …]`). One row per
            // (workspace_owner, project) — the unique index
            // collapses cleanly when more vaults appear under the
            // same project on a re-run.
            DB::statement(<<<'SQL'
                INSERT INTO deferred_access_grants
                    (user_id, workspace_id, provisioned_by, project_id, mode, project_role, resources, created_at)
                SELECT
                    o.owner_id           AS user_id,
                    o.id                 AS workspace_id,
                    o.owner_id           AS provisioned_by,
                    p.id                 AS project_id,
                    'project'            AS mode,
                    'owner'              AS project_role,
                    jsonb_agg(jsonb_build_object('vault_id', v.id))   AS resources,
                    NOW()                AS created_at
                FROM vaults v
                JOIN projects p       ON p.id = v.project_id
                JOIN organisations o  ON o.id = p.organisation_id
                WHERE o.owner_id IS NOT NULL
                  AND EXISTS (
                      SELECT 1 FROM resource_keys rk
                       WHERE rk.resource_type = 'vault' AND rk.resource_id = v.id
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM resource_keys rk2
                       WHERE rk2.resource_type = 'vault'
                         AND rk2.resource_id   = v.id
                         AND rk2.user_id       = o.owner_id
                  )
                GROUP BY o.id, o.owner_id, p.id
                ON CONFLICT (user_id, project_id) DO NOTHING
            SQL);
        });
    }

    public function down(): void
    {
        // No safe reverse. Rolling back this migration would mean
        // ripping owners off resources they legitimately have
        // access to, and orphaning client-side wraps that may
        // already have completed via the deferred-access flow.
        // The forward statements are idempotent; if the migration
        // ever needs to be re-run, doing so without a `down` is
        // safe.
    }
};
