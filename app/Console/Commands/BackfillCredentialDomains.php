<?php

namespace App\Console\Commands;

use App\Services\Vault\DomainExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot backfill for credentials.domain. Idempotent — safe to
 * re-run; overwrites any existing value with a fresh extraction so
 * stale/NULL domains get fixed.
 */
class BackfillCredentialDomains extends Command
{
    protected $signature = 'credentials:backfill-domains';

    protected $description = 'Re-extract and populate the domain column on all credentials that have a URL.';

    public function handle(DomainExtractor $extractor): int
    {
        $updated = 0;

        DB::table('credentials')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($extractor, &$updated): void {
                foreach ($rows as $row) {
                    $domain = $extractor->extract($row->url);
                    if ($domain !== $row->domain) {
                        DB::table('credentials')
                            ->where('id', $row->id)
                            ->update(['domain' => $domain]);
                        $updated++;
                    }
                }
            });

        $this->info("Updated {$updated} credential domain(s).");

        return self::SUCCESS;
    }
}
