<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compat clean-up for the consolidated-invitation rewrite. Every
 * pending `workspace_invitations` row created BEFORE this migration
 * ran against a DB that has the new `workspace_invitation_project_grants`
 * table means it's from the old bare-invite flow — it has no staged
 * project grants and the client has no way to re-submit them. Cancel
 * them cleanly; inviters get a fresh slate with the new shape.
 *
 * The `created_at < $cutoff` clause is load-bearing: the new model
 * legitimately allows zero project grants ("just join the workspace"
 * case), so without the timestamp we'd cancel every empty-grant
 * pending invite forever.
 *
 * Logged row count goes to both the migration output and the app log
 * so the deploy report can be cross-checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cutoff = now();

        $cancelled = DB::transaction(function () use ($cutoff): int {
            return DB::table('workspace_invitations')
                ->where('status', 'pending')
                ->where('created_at', '<', $cutoff)
                ->whereNotExists(function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('workspace_invitation_project_grants')
                        ->whereColumn('workspace_invitation_project_grants.invitation_id', 'workspace_invitations.id');
                })
                ->update([
                    'status' => 'declined',
                    'declined_at' => $cutoff,
                ]);
        });

        $msg = "Cancelled {$cancelled} legacy pending workspace invitations (pre-consolidated-invite shape).";

        // Migrator runs sans TTY in production; echo'd lines still
        // show up in `artisan migrate` output, and the Log entry is
        // the durable record for the deploy report.
        echo '  '.$msg.PHP_EOL;

        Log::info($msg, ['migration' => basename(__FILE__)]);
    }

    public function down(): void
    {
        // Intentional no-op — we can't reliably reverse a cancellation
        // (the row's previous status was already 'pending' but we
        // don't know which rows were ours). Operators that need a
        // rollback restore from DB snapshot.
    }
};
