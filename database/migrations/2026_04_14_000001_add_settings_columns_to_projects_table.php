<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->boolean('auto_archive_completed')->default(false)->after('is_archived');
            $table->unsignedSmallInteger('archive_retention_days')->default(90)->after('auto_archive_completed');
            $table->foreignId('original_owner_id')->nullable()->after('owner_id')->constrained('users');
            $table->index('original_owner_id');
        });

        // Backfill original_owner_id: prefer the earliest project-scoped
        // owner grant in resource_permissions; fall back to projects.owner_id.
        DB::statement(<<<'SQL'
            UPDATE projects p SET original_owner_id = COALESCE((
                SELECT rp.user_id
                FROM resource_permissions rp
                WHERE rp.resource_type = 'project'
                  AND rp.resource_id = p.id
                  AND rp.role = 'owner'
                ORDER BY rp.created_at ASC, rp.id ASC
                LIMIT 1
            ), p.owner_id)
            WHERE p.original_owner_id IS NULL
        SQL);

        DB::statement('ALTER TABLE projects ALTER COLUMN original_owner_id SET NOT NULL');

        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_archive_retention_days_check CHECK (archive_retention_days IN (30, 90, 180))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_archive_retention_days_check');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('original_owner_id');
            $table->dropColumn(['auto_archive_completed', 'archive_retention_days']);
        });
    }
};
