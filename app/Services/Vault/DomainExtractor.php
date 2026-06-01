<?php

namespace App\Services\Vault;

use Illuminate\Support\Facades\Cache;
use Pdp\Domain;
use Pdp\Rules;

/**
 * Extracts the registrable domain (eTLD+1) from a URL or hostname.
 *
 * Uses the Mozilla Public Suffix List via jeremykendall/php-domain-parser
 * so that:
 *   - api.github.com → github.com
 *   - foo.co.uk → foo.co.uk (co.uk is a public suffix)
 *   - mail.google.com → google.com
 *
 * The PSL is fetched once from publicsuffix.org and cached for 7 days.
 * Returns null for unparseable input, IP addresses, and localhost.
 */
class DomainExtractor
{
    private const PSL_CACHE_KEY = 'public_suffix_list_raw';

    private const PSL_CACHE_TTL = 604800; // 7 days

    private const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';

    /** In-memory singleton — parsed once per process, reused for every
     *  subsequent extract() call. Critical for batch operations (the
     *  backfill command iterates thousands of rows). */
    private ?Rules $parsedRules = null;

    private bool $rulesFetchAttempted = false;

    public function extract(?string $urlOrHost): ?string
    {
        if ($urlOrHost === null || $urlOrHost === '') {
            return null;
        }

        $host = $urlOrHost;
        if (str_contains($urlOrHost, '://')) {
            $parsed = parse_url($urlOrHost, PHP_URL_HOST);
            if (! is_string($parsed) || $parsed === '') {
                return null;
            }
            $host = $parsed;
        }

        $host = strtolower(trim($host, '.'));

        if (filter_var($host, FILTER_VALIDATE_IP) || $host === 'localhost') {
            return null;
        }

        try {
            $rules = $this->rules();
            if ($rules === null) {
                return $this->fallbackExtract($host);
            }

            $result = $rules->resolve(Domain::fromIDNA2008($host));
            $registrable = $result->registrableDomain()->toString();

            return $registrable !== '' ? $registrable : null;
        } catch (\Throwable) {
            return $this->fallbackExtract($host);
        }
    }

    private function rules(): ?Rules
    {
        if ($this->parsedRules !== null) {
            return $this->parsedRules;
        }

        // Only attempt the fetch/parse once per process — if the PSL
        // is unreachable, don't retry on every row in a batch.
        if ($this->rulesFetchAttempted) {
            return null;
        }

        $this->rulesFetchAttempted = true;

        $raw = Cache::remember(self::PSL_CACHE_KEY, self::PSL_CACHE_TTL, function (): ?string {
            $content = @file_get_contents(self::PSL_URL);

            return $content !== false ? $content : null;
        });

        if ($raw !== null) {
            $this->parsedRules = Rules::fromString($raw);
        }

        return $this->parsedRules;
    }

    /**
     * Naive fallback when the PSL isn't available (offline env, cache
     * cold on first request). Takes the last two labels — correct for
     * .com/.org/.net; incorrect for .co.uk but safe enough as a
     * degraded-accuracy fallback that doesn't break the endpoint.
     */
    private function fallbackExtract(string $host): ?string
    {
        $parts = explode('.', $host);
        if (count($parts) < 2) {
            return null;
        }

        return implode('.', array_slice($parts, -2));
    }
}
