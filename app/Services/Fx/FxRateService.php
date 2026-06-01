<?php

namespace App\Services\Fx;

use DateTimeInterface;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * FX rate provider backed by exchangeratesapi.io.
 *
 * The daily `fx:fetch` cron makes one upstream call (with the configured
 * base + symbol allow-list) and writes a per-base, per-day cross-rate
 * matrix into the cache. Reads always go to the cache first; if the
 * cache is cold we attempt to refresh, falling back to the most recent
 * cached entry within 7 days. If even that is unavailable we raise
 * {@see FxUnavailableException} so the controller can return 502.
 */
class FxRateService
{
    private const FALLBACK_DAYS = 7;

    private const SCALE_INTERMEDIATE = 8;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly array $config,
    ) {}

    /**
     * Convert $amount from $from to $to. Returns the converted amount as
     * a BCMath-precision string rounded to 2 decimal places.
     */
    public function convert(string $from, string $to, string $amount, ?DateTimeInterface $on = null): string
    {
        $from = strtoupper($from);
        $to = strtoupper($to);

        if ($from === $to) {
            return $this->roundTo2((string) $amount);
        }

        $rate = $this->rate($from, $to, $on);

        return $this->roundTo2(bcmul((string) $amount, $rate, self::SCALE_INTERMEDIATE));
    }

    /**
     * Return rates for $codes expressed against $base.
     *
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    public function ratesFor(array $codes, string $base, ?DateTimeInterface $on = null): array
    {
        $base = strtoupper($base);
        $book = $this->ratebook($base, $on);

        $out = [];
        foreach ($codes as $code) {
            $code = strtoupper($code);
            if (! array_key_exists($code, $book)) {
                throw new FxUnsupportedCurrencyException($code);
            }
            $out[$code] = $book[$code];
        }

        return $out;
    }

    /**
     * Calls the upstream once, derives the full cross-rate matrix from
     * the configured base + symbols, persists each base sub-dict to the
     * cache, and returns the matrix. Used by the daily cron and as the
     * lazy refresh path inside ratebook().
     *
     * @return array<string, array<string, string>>
     */
    public function refresh(?DateTimeInterface $on = null): array
    {
        $date = $this->dateString($on);
        $configuredBase = strtoupper((string) ($this->config['base'] ?? 'USD'));
        $symbols = array_map('strtoupper', (array) ($this->config['symbols'] ?? []));

        // Always include the base in the fetched ratebook so cross-rate
        // calculations have a row for it (rate(base→base) = 1).
        $allCodes = array_values(array_unique(array_merge([$configuredBase], $symbols)));

        $response = $this->http->get($this->config['url'] ?? '', [
            'access_key' => $this->config['key'] ?? '',
            'base' => $configuredBase,
            'symbols' => implode(',', array_values(array_diff($allCodes, [$configuredBase]))),
        ]);

        if ($response->failed()) {
            throw new \RuntimeException('FX upstream returned status '.$response->status());
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? false) !== true || ! is_array($payload['rates'] ?? null)) {
            $errorCode = $payload['error']['code'] ?? null;
            if ($errorCode !== null) {
                throw new \RuntimeException('FX upstream error: '.$errorCode);
            }
            throw new \RuntimeException('FX upstream returned malformed payload');
        }

        $rates = [];
        foreach ($payload['rates'] as $code => $value) {
            $rates[strtoupper((string) $code)] = $this->normaliseRate($value);
        }
        $rates[$configuredBase] = '1';

        // Cross-rate matrix: rate(A→B) = rate(USD→B) / rate(USD→A).
        $matrix = [];
        foreach ($rates as $from => $_) {
            foreach ($rates as $to => $_2) {
                if ($from === $to) {
                    $matrix[$from][$to] = '1';
                    continue;
                }
                $matrix[$from][$to] = bcdiv($rates[$to], $rates[$from], self::SCALE_INTERMEDIATE);
            }
            $this->cache->put($this->cacheKey($from, $date), $matrix[$from], now()->addHours(25));
        }

        return $matrix;
    }

    private function rate(string $from, string $to, ?DateTimeInterface $on): string
    {
        $book = $this->ratebook($from, $on);

        if (! array_key_exists($to, $book)) {
            throw new FxUnsupportedCurrencyException($to);
        }

        return $book[$to];
    }

    /**
     * @return array<string, string>
     */
    private function ratebook(string $base, ?DateTimeInterface $on): array
    {
        $date = $this->dateString($on);
        $cached = $this->cache->get($this->cacheKey($base, $date));

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $matrix = $this->refresh($on);

            if (! array_key_exists($base, $matrix)) {
                throw new FxUnsupportedCurrencyException($base);
            }

            return $matrix[$base];
        } catch (FxUnsupportedCurrencyException $e) {
            throw $e;
        } catch (Throwable $e) {
            for ($i = 1; $i <= self::FALLBACK_DAYS; $i++) {
                $back = Carbon::parse($date)->subDays($i)->toDateString();
                $fallback = $this->cache->get($this->cacheKey($base, $back));
                if (is_array($fallback)) {
                    return $fallback;
                }
            }

            throw new FxUnavailableException($e);
        }
    }

    private function cacheKey(string $base, string $date): string
    {
        return "fx:{$base}:{$date}";
    }

    private function dateString(?DateTimeInterface $on): string
    {
        return ($on ? Carbon::instance($on) : Carbon::now())->toDateString();
    }

    private function normaliseRate(mixed $raw): string
    {
        if (is_string($raw)) {
            return $raw;
        }
        if (is_int($raw) || is_float($raw)) {
            return number_format((float) $raw, self::SCALE_INTERMEDIATE, '.', '');
        }
        throw new \RuntimeException('FX upstream returned non-numeric rate');
    }

    private function roundTo2(string $value): string
    {
        $bump = str_starts_with($value, '-') ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $bump, self::SCALE_INTERMEDIATE), '0', 2);
    }
}
