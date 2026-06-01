<?php

use App\Services\Vault\DomainExtractor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credential URL Lookup spec §5 Option A: indexed `domain` column
 * populated on write so the browser extension's
 * `GET /me/credentials/by-url` query is a simple index scan. Backfills
 * existing rows by extracting eTLD+1 from the `url` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->string('domain', 253)->nullable()->after('url');
            $table->index('domain');
        });

        // Backfill: extract domain from every credential that has a
        // URL. Done in PHP so the DomainExtractor's eTLD+1 logic is
        // the single source of truth (no SQL reimplementation).
        $extractor = app(DomainExtractor::class);

        DB::table('credentials')
            ->whereNotNull('url')
            ->where('url', '!=', '')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($extractor): void {
                foreach ($rows as $row) {
                    $domain = $extractor->extract($row->url);
                    if ($domain !== null) {
                        DB::table('credentials')
                            ->where('id', $row->id)
                            ->update(['domain' => $domain]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropIndex(['domain']);
            $table->dropColumn('domain');
        });
    }
};
