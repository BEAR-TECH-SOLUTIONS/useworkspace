<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workspaces spec, commit B — rip the project-scope invitation plane.
 *
 * `invitations` + `invitation_vault_keys` are both gone: the
 * consolidated `workspace_invitations` + `workspace_invitation_*`
 * tables cover every flow the old pair used to serve. Any pending
 * rows at this point are from legacy tests or dev workspaces — no
 * production data is in flight.
 *
 * Dropped in child-then-parent order so the `invitation_vault_keys`
 * FK to `invitations` unwinds cleanly. Custom enum types used only
 * by these tables drop after the tables themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('invitation_vault_keys');
        Schema::dropIfExists('invitations');

        DB::statement('DROP TYPE IF EXISTS invitation_status');
        DB::statement('DROP TYPE IF EXISTS invitation_scope');
    }

    public function down(): void
    {
        // Intentional no-op — the shape these tables had is only
        // reconstructible by re-running every migration up to
        // 2026_04_11_000001. Operators that need the legacy plane
        // back restore from a pre-commit-B snapshot.
    }
};
