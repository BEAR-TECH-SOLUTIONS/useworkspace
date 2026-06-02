<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated-invitation spec, commit A. Extends
 * `workspace_invitations` with three FK-cascaded side tables so the
 * admin can stage workspace membership + per-project access + per-
 * resource grants + wrapped vault keys in a single payload, applied
 * atomically on accept.
 *
 * Side table layout is per-project-grant (not per-invitation flat) so
 * each project's mode/role/resources are grouped together and the
 * accept handler can partial-drop a single project grant without
 * unravelling the rest.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE workspace_invitation_grant_mode AS ENUM ('project', 'resources')");

        Schema::create('workspace_invitation_project_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_id')->constrained('workspace_invitations')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();

            $table->unique(['invitation_id', 'project_id']);
            $table->index('project_id');
        });

        // mode / project_role live on enum types (Postgres rejects
        // invalid values at the DB layer). project_role is nullable —
        // required only when mode='project' (enforced in the service).
        DB::statement('ALTER TABLE workspace_invitation_project_grants ADD COLUMN mode workspace_invitation_grant_mode NOT NULL');
        DB::statement('ALTER TABLE workspace_invitation_project_grants ADD COLUMN project_role member_role NULL');

        Schema::create('workspace_invitation_resource_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_project_id')
                ->constrained('workspace_invitation_project_grants')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('resource_id');
            $table->text('encrypted_key')->nullable();
            $table->unsignedInteger('key_version')->nullable();
            // Set by the rotate-key cascade when the wrapped key this
            // row stages is no longer at the vault's current version.
            // Accept-time re-checks both this flag and the live
            // key_version — belt-and-braces.
            $table->timestamp('superseded_at')->nullable();

            $table->unique(['invitation_project_id', 'resource_id']);
        });

        DB::statement('ALTER TABLE workspace_invitation_resource_grants ADD COLUMN resource_type resource_kind NOT NULL');
        DB::statement('ALTER TABLE workspace_invitation_resource_grants ADD COLUMN role member_role NOT NULL');

        // Pattern A staged vault keys (mode='project') live in a
        // dedicated table because the project grant doesn't carry
        // per-resource rows. Same shape the legacy
        // `invitation_vault_keys` used, scoped to a project grant.
        Schema::create('workspace_invitation_vault_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invitation_project_id')
                ->constrained('workspace_invitation_project_grants')
                ->cascadeOnDelete();
            $table->foreignId('vault_id')->constrained('vaults')->cascadeOnDelete();
            $table->text('encrypted_key');
            $table->unsignedInteger('key_version');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['invitation_project_id', 'vault_id']);
            $table->index('vault_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitation_vault_keys');
        Schema::dropIfExists('workspace_invitation_resource_grants');
        Schema::dropIfExists('workspace_invitation_project_grants');
        DB::statement('DROP TYPE IF EXISTS workspace_invitation_grant_mode');
    }
};
