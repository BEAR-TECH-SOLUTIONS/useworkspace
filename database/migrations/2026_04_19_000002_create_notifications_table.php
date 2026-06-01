<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Global notifications plane. Spans every workspace/project — the list
 * endpoint is global so the client can surface a single inbox.
 *
 * Denormalization rule (CLAUDE.md mirror of the spec §3): actor_name,
 * workspace_name, and project_name are snapshotted at write time so the
 * list endpoint is a single index range scan; renaming a project later
 * does NOT rewrite existing notification rows.
 *
 * The `workspace_id` column references `organisations(id)` — the DB
 * table is named `organisations` but every API surface says "workspace"
 * (see the rename decision comment in WorkspaceController §1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 50);
            $table->text('title');
            $table->text('body')->nullable();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('actor_name')->nullable();

            $table->foreignId('workspace_id')->nullable()->constrained('organisations')->cascadeOnDelete();
            $table->text('workspace_name')->nullable();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->text('project_name')->nullable();

            $table->string('resource_type', 30)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();

            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));

            $table->boolean('is_read')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'is_read', 'created_at'], 'idx_notifications_user_unread');
            $table->index(['user_id', 'created_at'], 'idx_notifications_user_created');
            // Cron-side dedup lookup: "does (user, type, resource) already
            // have a row newer than N hours/days?" — powered by this index.
            $table->index(['user_id', 'type', 'resource_type', 'resource_id', 'created_at'], 'idx_notifications_dedup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
