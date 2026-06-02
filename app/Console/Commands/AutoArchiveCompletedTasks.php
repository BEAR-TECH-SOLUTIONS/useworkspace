<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hourly worker for the project-settings auto-archive feature.
 *
 * Two passes, both executed as single bulk statements that touch only
 * rows actually needing change:
 *   1. Archive task items completed more than ARCHIVE_DELAY_HOURS ago
 *      in any project with `auto_archive_completed = true`.
 *   2. Hard-delete already-archived items older than the project's
 *      `archive_retention_days`. Retention differs per project so the
 *      cutoff is computed inline using Postgres interval arithmetic.
 */
class AutoArchiveCompletedTasks extends Command
{
    protected $signature = 'tasks:auto-archive';

    protected $description = 'Archive completed tasks and prune archived ones past the project retention window.';

    /**
     * Grace window between a task being completed and the auto-archive
     * pass picking it up. Lets a user unmark a mis-clicked "done" without
     * losing the task off the board.
     */
    public const ARCHIVE_DELAY_HOURS = 1;

    public function handle(): int
    {
        $archiveCutoff = Carbon::now()->subHours(self::ARCHIVE_DELAY_HOURS);

        // Archive pass — one UPDATE joined to projects. The WHERE clause
        // pins writes to rows that actually flip is_archived, so a run
        // with nothing to do is a single index scan.
        $archived = DB::update(
            <<<'SQL'
            UPDATE task_items ti
               SET is_archived = true
              FROM projects p
             WHERE ti.project_id = p.id
               AND p.auto_archive_completed = true
               AND ti.is_completed = true
               AND ti.is_archived = false
               AND ti.completed_at IS NOT NULL
               AND ti.completed_at <= ?
            SQL,
            [$archiveCutoff],
        );

        // Retention pass — DELETE joined to projects so each row is
        // evaluated against its own project's archive_retention_days.
        // The interval math stays in SQL; no per-project round-trips.
        $deleted = DB::delete(
            <<<'SQL'
            DELETE FROM task_items
             USING projects p
             WHERE task_items.project_id = p.id
               AND p.auto_archive_completed = true
               AND task_items.is_archived = true
               AND task_items.updated_at < now() - (p.archive_retention_days || ' days')::interval
            SQL,
        );

        $this->info("Archived: {$archived}");
        $this->info("Hard-deleted: {$deleted}");

        return self::SUCCESS;
    }
}
