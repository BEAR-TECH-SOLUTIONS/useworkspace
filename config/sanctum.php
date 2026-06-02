<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | The TeamCore API is consumed purely by bearer-token clients (the Tauri
    | desktop app and future CLI/integrations). No first-party SPA uses
    | Sanctum's cookie/stateful path, so we deliberately leave this empty.
    */

    'stateful' => explode(',', (string) env(
        'SANCTUM_STATEFUL_DOMAINS',
        sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            Sanctum::currentApplicationUrlWithPort(),
        )
    )),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes (audit H5)
    |--------------------------------------------------------------------------
    |
    | Bearer tokens expire after this many minutes of *issuance* — not of
    | last activity. Default is 14 days for desktop ergonomics; 2FA-gated
    | endpoints already require a fresh 2fa_verified flag every 10 minutes
    | so a stolen long-lived token still can't reach sensitive operations
    | without the second factor. Set SANCTUM_TOKEN_TTL_MINUTES=null to opt
    | out of expiration in tightly-controlled deployments.
    */

    'expiration' => (function () {
        $raw = env('SANCTUM_TOKEN_TTL_MINUTES', 60 * 24 * 14);
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }

        return (int) $raw;
    })(),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | A short, stable prefix on plaintext tokens so GitHub/secret-scanning
    | rules can flag leaks confidently. Not security-critical — the hash on
    | the row is what's checked at runtime — but cheap defence in depth.
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'tcpat_'),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
