<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("DROP TYPE IF EXISTS invitation_scope CASCADE");
        DB::statement("CREATE TYPE invitation_scope AS ENUM ('project', 'resource')");
        DB::statement("DROP TYPE IF EXISTS invitation_status CASCADE");
        DB::statement("CREATE TYPE invitation_status AS ENUM ('pending', 'accepted', 'declined')");

        Schema::create('invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('invitee_email');
            $table->jsonb('resource_grants')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE invitations ADD COLUMN scope invitation_scope NOT NULL");
        DB::statement("ALTER TABLE invitations ADD COLUMN status invitation_status NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE invitations ADD COLUMN project_role member_role");

        // Only one pending invitation per user per project.
        DB::statement("CREATE UNIQUE INDEX invitations_pending_unique ON invitations (project_id, invitee_id) WHERE status = 'pending'");
        DB::statement("CREATE INDEX invitations_invitee_idx ON invitations (invitee_id, status)");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        DB::statement("DROP TYPE IF EXISTS invitation_scope CASCADE");
        DB::statement("DROP TYPE IF EXISTS invitation_status CASCADE");
    }
};
