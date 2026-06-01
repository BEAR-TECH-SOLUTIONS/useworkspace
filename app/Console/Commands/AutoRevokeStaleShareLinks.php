<?php

namespace App\Console\Commands;

use App\Models\Vault\ShareLink;
use App\Services\Sharing\ShareLinkRevoker;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Weekly housekeeping: auto-revoke share links that nobody has ever
 * opened in the last 60 days. Plan §14.
 *
 * "Inactive for 60 days and never accessed" almost certainly means the
 * sender forgot, the recipient lost interest, or the URL never made it
 * to its destination. Better to revoke quietly than to leave a viable
 * URL hanging in someone's inbox.
 */
class AutoRevokeStaleShareLinks extends Command
{
    protected $signature = 'shares:auto-revoke-stale';

    protected $description = 'Auto-revoke share links with zero views older than 60 days (Universal Share Links plan §14).';

    public function handle(ShareLinkRevoker $revoker): int
    {
        $cutoff = Carbon::now()->subDays(60);

        $count = 0;

        ShareLink::query()
            ->whereNull('revoked_at')
            ->where('view_count', 0)
            ->where('created_at', '<', $cutoff)
            ->lazy()
            ->each(function (ShareLink $link) use ($revoker, &$count): void {
                if ($revoker->revokeOne($link, 'inactivity_auto_revoke')) {
                    $count++;
                }
            });

        $this->info("Auto-revoked stale share links: {$count}");

        return self::SUCCESS;
    }
}
