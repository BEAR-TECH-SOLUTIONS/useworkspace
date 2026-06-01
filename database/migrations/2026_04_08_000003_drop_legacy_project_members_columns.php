<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the four legacy columns on project_members now that the authorization
 * plane lives in resource_permissions and the crypto plane lives in
 * resource_keys. Clean cutover — no deprecation window.
 *
 * Removed columns:
 *   - encrypted_project_key → resource_keys (resource_type='project')
 *   - vault_role            → resource_permissions (resource_type='vault')
 *   - tasks_role            → resource_permissions (resource_type='board')
 *   - expenses_role         → resource_permissions (resource_type='bucket')
 *
 * All application code that read these columns has been rewritten in the
 * same PR. The 2026_04_08_000002 backfill has already copied every row
 * into the new plane before this migration runs.
 *
 * down() recreates the columns nullable. A rollback will leave them empty —
 * there is no reverse-backfill path, the previous PR's backfill already
 * consumed the data and a rollback in staging implies the whole release is
 * being reverted (backend + clients together).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_members', function ($table): void {
            $table->dropColumn([
                'encrypted_project_key',
                'vault_role',
                'tasks_role',
                'expenses_role',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function ($table): void {
            $table->text('encrypted_project_key')->nullable();
        });

        DB::statement('ALTER TABLE project_members ADD COLUMN vault_role member_role');
        DB::statement('ALTER TABLE project_members ADD COLUMN tasks_role member_role');
        DB::statement('ALTER TABLE project_members ADD COLUMN expenses_role member_role');
    }
};