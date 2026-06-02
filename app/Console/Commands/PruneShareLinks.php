<?php

namespace App\Console\Commands;

use App\Models\Vault\ShareLink;
use App\Models\Vault\ShareLinkView;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneShareLinks extends Command
{
    protected $signature = 'shares:prune';

    protected $description = 'Soft-revoke expired share links and hard-delete rows revoked > 30 days ago (CLAUDE.md §10.5).';

    public function handle(): int
    {
        $now = Carbon::now();

        $softRevoked = ShareLink::query()
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', $now)
            ->update(['revoked_at' => $now]);

        $cutoff = $now->copy()->subDays(30);

        $toDelete = ShareLink::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->pluck('id');

        if ($toDelete->isNotEmpty()) {
            ShareLinkView::query()->whereIn('share_link_id', $toDelete)->delete();
            ShareLink::query()->whereIn('id', $toDelete)->delete();
        }

        $this->info("Soft-revoked: {$softRevoked}");
        $this->info('Hard-deleted: '.$toDelete->count());

        return self::SUCCESS;
    }
}
