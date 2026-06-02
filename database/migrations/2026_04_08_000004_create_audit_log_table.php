<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generic per-project audit log. Separate from task_activities (CLAUDE.md §9),
 * which is a *domain* log for kanban actions — this one is the *access plane*
 * log for grants, rotations, migrations, and membership changes.
 *
 * Every App\Services\Permissions\PermissionService mutation writes into this
 * table inside the same transaction as the state change. Controllers never
 * write here directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('ALTER TABLE audit_log ADD COLUMN resource_type resource_kind NULL');

        DB::statement('CREATE INDEX audit_log_project_idx  ON audit_log (project_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_log_actor_idx    ON audit_log (actor_user_id, created_at DESC)');
        DB::statement('CREATE INDEX audit_log_resource_idx ON audit_log (resource_type, resource_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};