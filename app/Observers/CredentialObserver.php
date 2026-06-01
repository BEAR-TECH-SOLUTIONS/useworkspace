<?php

namespace App\Observers;

use App\Models\Vault\Credential;
use App\Services\Vault\DomainExtractor;

/**
 * Keeps `credentials.domain` in sync with `credentials.url` on every
 * write. The column is indexed and powers the browser extension's
 * `GET /me/credentials/by-url` endpoint.
 */
class CredentialObserver
{
    public function __construct(private readonly DomainExtractor $extractor) {}

    public function saving(Credential $credential): void
    {
        // Always recompute — the isDirty('url') optimization saved
        // ~1μs per save but any code path that sets url without
        // marking it dirty (forceFill, raw attribute sets, pre-
        // observer credentials) leaves domain stale. The extract()
        // call is cheap (cached PSL + string ops) and correctness
        // beats the micro-optimization.
        $credential->domain = $this->extractor->extract($credential->url);
    }
}
