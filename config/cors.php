<?php

return [

    // `up` is Laravel's stock health-check route; the desktop client
    // probes it before showing the login form so we can't leave it
    // CORS-excluded. `broadcasting/auth` is the Sanctum-gated handler
    // Reverb hits during WebSocket channel auth — same reasoning.
    'paths' => ['api/*', 'up', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    'allowed_methods' => ['*'],

    // Default origin list, comma-separated. Override per-env with
    // CORS_ALLOWED_ORIGINS in .env (the env var REPLACES this default,
    // so include every origin you need — there's no merge).
    //   • https://app.usework.space    — canonical web client.
    //   • https://app.teamcore.space   — legacy alias kept alive
    //     while the rename rolls out; safe to drop once every client
    //     build ships with the usework.space origin.
    //   • http://localhost:5173 / 3000 — local dev (Vite / Next).
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://app.usework.space,https://app.teamcore.space')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-XSRF-TOKEN',
        'X-Socket-Id',
        'Idempotency-Key',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
