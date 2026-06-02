<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace layer — Commit 2. Invitations to join a workspace (the
 * billing unit), separate from project-scope `invitations`. No crypto
 * plane: workspace membership grants identity + directory presence
 * only, and does NOT imply project access. That's still gated by
 * project owners via `POST /projects/{p}/members/direct` in commit 3.
 *
 * Seat cap is enforced at create-time (§6 bullet 1) AND at accept-time
 * (§6 bullet 2, race guard). The unique partial index on
 * (workspace_id, invitee_email) WHERE status='pending' keeps a single
 * outstanding invite per email per workspace.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE TYPE workspace_invitation_status AS ENUM ('pending', 'accepted', 'declined', 'expired')");

        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('organisations')->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users');
            $table->string('invitee_email');
            $table->foreignId('invitee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'invitee_email']);
            $table->index(['invitee_id']);
        });

        // Roles and status live on enum types so Postgres rejects junk
        // values at the DB layer, same pattern the project invitations
        // table uses.
        DB::statement('ALTER TABLE workspace_invitations ADD COLUMN role organisation_role NOT NULL');
        DB::statement("ALTER TABLE workspace_invitations ADD COLUMN status workspace_invitation_status NOT NULL DEFAULT 'pending'");

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_invitations_pending_unique
                ON workspace_invitations (workspace_id, lower(invitee_email))
             WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
        DB::statement('DROP TYPE IF EXISTS workspace_invitation_status');
    }
};
