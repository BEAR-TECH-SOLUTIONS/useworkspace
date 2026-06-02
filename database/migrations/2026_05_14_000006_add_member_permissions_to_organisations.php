<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-toggleable per-workspace permissions for non-admin members.
 * Default is permissive (both true) — flipping a flag to false makes
 * the corresponding action admin-only without taking the workspace
 * read-only as a whole.
 *
 *   members_can_create_projects=false → only admins (and owner) can
 *     POST /projects in this workspace.
 *   members_can_invite_members=false → only admins can POST
 *     /workspaces/{w}/invitations. The /me/workspace-invitations
 *     accept path stays unaffected — the invitee always controls
 *     accepting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->boolean('members_can_create_projects')
                ->default(true)
                ->after('member_count');
            $table->boolean('members_can_invite_members')
                ->default(true)
                ->after('members_can_create_projects');
        });
    }

    public function down(): void
    {
        Schema::table('organisations', function (Blueprint $table): void {
            $table->dropColumn(['members_can_create_projects', 'members_can_invite_members']);
        });
    }
};
