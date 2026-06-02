<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deferred project/resource grants for provisioned users.
 *
 * The admin's intent is captured at `POST /workspaces/{w}/provision-user`
 * but can't be applied immediately: the new user has no public key yet,
 * so there is nothing to wrap vault keys against. Each row stores a
 * single project's worth of intended grants and is deleted once an
 * owner finalises it (wraps keys + promotes to resource_permissions).
 *
 * One row per (user, project) — UNIQUE constraint — so re-provisioning
 * the same user/project pair overwrites rather than duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deferred_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignId('provisioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('mode', 20);
            $table->string('project_role', 20)->nullable();
            $table->jsonb('resources')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'project_id'], 'deferred_access_user_project_unique');
            $table->index('workspace_id', 'idx_deferred_access_workspace');
            $table->index('user_id', 'idx_deferred_access_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deferred_access_grants');
    }
};
