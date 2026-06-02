<?php

namespace App\Providers;

use App\Models\Docs\Doc;
use App\Models\Expenses\Expense;
use App\Models\Identity\OrganisationMember;
use App\Models\Tasks\TaskBoard;
use App\Models\Tasks\TaskItem;
use App\Models\Vault\Credential;
use App\Observers\CredentialObserver;
use App\Observers\OrganisationMemberObserver;
use App\Services\Fx\FxRateService;
use App\Services\Licensing\Ed25519Signer;
use App\Services\Licensing\Ed25519Verifier;
use App\Services\Workspaces\Billing\BillingDriver;
use App\Services\Workspaces\Billing\NullBillingDriver;
use App\Services\Workspaces\Billing\SandboxBillingDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // DomainExtractor holds the parsed PSL Rules object in memory —
        // singleton ensures the 250KB parse happens once per process.
        $this->app->singleton(\App\Services\Vault\DomainExtractor::class);

        // Billing driver is env-selected — defaults to the null driver
        // so production never silently turns on sandbox. Stripe driver
        // slots in here once the integration lands.
        $this->app->singleton(BillingDriver::class, function ($app) {
            return match ((string) config('billing.driver', 'none')) {
                'sandbox' => $app->make(SandboxBillingDriver::class),
                default => $app->make(NullBillingDriver::class),
            };
        });

        $this->app->singleton(FxRateService::class, function ($app) {
            return new FxRateService(
                $app->make(\Illuminate\Http\Client\Factory::class),
                $app->make(\Illuminate\Contracts\Cache\Repository::class),
                (array) config('services.exchange_rates', []),
            );
        });

        // Lazy: callers must explicitly resolve via the container.
        // Self-hosted (Phase 5+) will bind a different verifier that
        // reads the baked-in public key path from a different env.
        $this->app->singleton(Ed25519Signer::class, function () {
            $raw = (string) config('teamcore.license.signing_private_key', '');
            $key = base64_decode($raw, true);
            if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
                throw new \RuntimeException('LICENSE_SIGNING_PRIVATE_KEY is unset or malformed; run `php artisan tc:license:keygen`.');
            }

            return new Ed25519Signer($key);
        });

        $this->app->singleton(Ed25519Verifier::class, function () {
            $path = (string) config('teamcore.license.verifying_public_key_path');
            if ($path === '' || ! is_readable($path)) {
                throw new \RuntimeException('License verifying public key not readable at '.$path);
            }
            $key = $this->decodePemPublicKey((string) file_get_contents($path));

            return new Ed25519Verifier($key);
        });
    }

    /**
     * Strip a single-block PEM wrapper and return the raw 32-byte
     * Ed25519 public key. Accepts either the bare "BEGIN PUBLIC KEY"
     * (SPKI DER) form or our minimal "BEGIN ED25519 PUBLIC KEY"
     * (raw base64) form. Keeping the second form means tooling can
     * round-trip the key without dragging in SPKI ASN.1 helpers.
     */
    private function decodePemPublicKey(string $pem): string
    {
        // Audit L4: previously we stripped ANY `-----…-----` label,
        // so a PEM headed `BEGIN RSA PRIVATE KEY` (or anything else)
        // would parse if the embedded base64 happened to be 32 or 44
        // bytes. Pin to the two specific labels we emit so a wrong
        // file fails loudly instead of being interpreted as Ed25519.
        if (! preg_match(
            '/-----BEGIN (?:ED25519 )?PUBLIC KEY-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END (?:ED25519 )?PUBLIC KEY-----/',
            $pem,
            $matches,
        )) {
            throw new \RuntimeException('License verifying public key PEM has an unexpected label (want BEGIN PUBLIC KEY or BEGIN ED25519 PUBLIC KEY).');
        }

        $stripped = preg_replace('/\s+/', '', $matches[1]) ?? '';

        $decoded = base64_decode($stripped, true);
        if ($decoded === false) {
            throw new \RuntimeException('License verifying public key PEM is malformed');
        }

        // SPKI DER: 12-byte AlgorithmIdentifier prefix for Ed25519,
        // followed by the 32-byte raw key. Trim it if present.
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES + 12) {
            return substr($decoded, 12);
        }
        if (strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $decoded;
        }
        throw new \RuntimeException('License verifying public key is the wrong length');
    }

    public function boot(): void
    {
        Credential::observe(CredentialObserver::class);
        OrganisationMember::observe(OrganisationMemberObserver::class);
        $this->configureRateLimiting();
        $this->configurePasswordPolicy();

        // Cloud admin gate — guards license issuance, revocation, and
        // listing. Self-hosted has no admin endpoints so this gate is
        // never consulted on that edition.
        \Illuminate\Support\Facades\Gate::define('cloud-admin', function (\App\Models\User $user): bool {
            return (bool) ($user->is_admin ?? false);
        });

        // Stable polymorphic aliases for ShareLink. Using morphMap()
        // (not enforceMorphMap) so it does NOT enable strict mode —
        // unrelated morph relations elsewhere (e.g. Sanctum's
        // PersonalAccessToken→User tokenable) keep working with their
        // default class-FQCN morph_type values.
        Relation::morphMap([
            'board' => TaskBoard::class,
            'task' => TaskItem::class,
            'credential' => Credential::class,
            'doc' => Doc::class,
            'expense' => Expense::class,
        ]);
    }

    /**
     * Sets the password complexity floor for every `Password::defaults()`
     * call across the codebase (RegisterRequest, ChangePasswordRequest,
     * CreateAdmin, share-link unlock, …). Audit H9 raises the bar
     * from the framework default of `min:8` to a 12-char mixed-case +
     * numbers + symbols requirement, plus a HaveIBeenPwned check on
     * non-testing environments. The HIBP lookup is skipped under
     * `testing` because it would hit the live API on every fixture.
     */
    private function configurePasswordPolicy(): void
    {
        \Illuminate\Validation\Rules\Password::defaults(function (): \Illuminate\Validation\Rules\Password {
            $rule = \Illuminate\Validation\Rules\Password::min(12)
                ->mixedCase()
                ->numbers()
                ->symbols();

            return $this->app->environment('production')
                ? $rule->uncompromised()
                : $rule;
        });
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $ip = $request->ip();
            $email = strtolower(trim((string) $request->input('email', '')));

            // Per-IP cap (covers shared-IP brute force) AND per-email
            // cap (covers proxy-rotation against a single account).
            // The combined floor of 5/min/IP + 5/min/IP+email + 50/h/email
            // means: shared NATs aren't griefed beyond a one-minute
            // window, and per-account online brute force is bounded
            // regardless of the attacker's IP budget — audit H5.
            return [
                Limit::perMinute(5)->by($ip),
                Limit::perHour(20)->by($ip),
                $email !== ''
                    ? Limit::perMinute(5)->by('login:user:'.$email)
                    : Limit::perMinute(20)->by($ip),
                $email !== ''
                    ? Limit::perHour(50)->by('login:user:'.$email)
                    : Limit::perHour(100)->by($ip),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perHour(10)->by($request->ip());
        });

        // Password-change endpoint (audit M16). Tight cap on per-user
        // + per-IP attempts to brute-force `current_password`.
        RateLimiter::for('password-change', function (Request $request) {
            $userId = $request->user()?->id;

            return [
                Limit::perMinute(5)->by($userId ?? $request->ip()),
                Limit::perHour(20)->by($userId ?? $request->ip()),
            ];
        });

        // Token refresh — honest clients call this once every
        // ~5 days (last third of a 14-day TTL). The cap is generous
        // enough that legitimate use never sees it but tight enough
        // that a misbehaving client / loop is bounded.
        RateLimiter::for('token-refresh', function (Request $request) {
            $userId = $request->user()?->id;

            return [
                Limit::perMinute(3)->by($userId ?? $request->ip()),
                Limit::perHour(10)->by($userId ?? $request->ip()),
            ];
        });

        // Public-key lookup — tightly capped to deter user enumeration
        // (audit H4). The endpoint also returns an empty shape for
        // unknown emails so the only signal an attacker gets is rate.
        RateLimiter::for('public-key-lookup', function (Request $request) {
            $userId = $request->user()?->id;

            return $userId
                ? [
                    Limit::perMinute(30)->by('pk:user:'.$userId),
                    Limit::perHour(200)->by('pk:user:'.$userId),
                ]
                : Limit::perMinute(10)->by($request->ip());
        });

        RateLimiter::for('api', function (Request $request) {
            $userId = $request->user()?->id;

            return $userId
                ? Limit::perMinute(120)->by($userId)
                : Limit::perMinute(20)->by($request->ip());
        });

        // Authenticated mutation rate limit for vault writes (CLAUDE.md §10).
        RateLimiter::for('vault', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?? $request->ip());
        });

        // Public share-link unlock: tight per-IP cap to deter brute-force
        // guessing of token + passphrase combinations (§10.6).
        RateLimiter::for('share-unlock', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(30)->by('share-unlock:'.($request->route('tokenHash') ?? 'all')),
            ];
        });

        // Public share-link metadata GET (audit M5). Previously
        // unthrottled, which let an attacker enumerate valid token
        // hashes cheaply once they had a leak. Tighter than /unlock
        // because the GET is much higher signal — it's a yes/no
        // existence oracle.
        RateLimiter::for('share-show', function (Request $request) {
            return [
                Limit::perMinute(30)->by($request->ip()),
                Limit::perHour(200)->by($request->ip()),
                Limit::perMinute(60)->by('share-show:'.($request->route('tokenHash') ?? 'all')),
            ];
        });

        // Public anti-phishing identity probe (self-hosted installs).
        // 60/min/ip is generous for honest desktop clients (they probe
        // once at login) but cheap enough to refuse a sustained signing
        // flood — every call invokes libsodium and reads env vars.
        RateLimiter::for('server-attest', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // Public landing waitlist: cap per IP to deter signup spam.
        // Honeypot + email RFC/DNS check + idempotent DB insert are
        // the deeper layers; see WaitlistController docblock.
        RateLimiter::for('waitlist', function (Request $request) {
            $ip = $request->ip();

            return [
                Limit::perMinute(5)->by($ip),
                Limit::perHour(20)->by($ip),
            ];
        });
    }
}
