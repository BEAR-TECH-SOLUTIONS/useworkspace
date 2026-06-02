<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the `project_members` table entirely. After Step A it was already a
 * shadow of `resource_permissions` rows at `resource_type='project'`; the
 * four load-bearing columns (encrypted_project_key + three sub-role
 * columns) were removed in 2026_04_08_000003, leaving nothing that
 * `resource_permissions` doesn't already answer more precisely.
 *
 * "Project membership" now lives exclusively in `resource_permissions`:
 *   - A Pattern A member has one row at (resource_type='project', resource_id=P).
 *   - A Pattern B user has rows only on specific child resources
 *     (board/vault/bucket) and is not a "project member" in the legacy
 *     sense — that's by design.
 *
 * Irreversible cutover: down() only recreates the empty table shell for
 * migration housekeeping. A real rollback would need a reverse backfill
 * from resource_permissions back into project_members, which this PR does
 * not provide because a rollback in this window implies the whole release
 * is being reverted (backend + clients together).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_members');
    }

    public function down(): void
    {
        Schema::create('project_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampsTz();
            $table->unique(['project_id', 'user_id']);
        });

        DB::statement('ALTER TABLE project_members ADD COLUMN role member_role NOT NULL');
    }
};