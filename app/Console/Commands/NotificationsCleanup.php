<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Daily retention pass. Read notifications are evicted 7 days after
 * creation; unread rows are kept indefinitely because the user
 * hasn't seen them yet. No per-user config — if we add one later,
 * fold it in here without moving the cutoff logic elsewhere.
 */
class NotificationsCleanup extends Command
{
    protected $signature = 'notifications:cleanup';

    protected $description = 'Delete read notifications older than the retention window.';

    public const RETENTION_DAYS = 7;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $deleted = Notification::query()
            ->where('is_read', true)
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info('[notifications:cleanup] deleted '.number_format($deleted)
            .' read notifications older than '.self::RETENTION_DAYS.' days');

        return self::SUCCESS;
    }
}
