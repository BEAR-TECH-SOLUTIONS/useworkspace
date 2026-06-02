<?php

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Default origin list, comma-separated. Override per-env with
    // CORS_ALLOWED_ORIGINS in .env (the env var REPLACES this default,
    // so include every origin you need — there's no merge).
    //   • https://app.teamcore.space   — canonical desktop / web
    //     client origin. Baked in so self-hosted installs work
    //     out of the box without each operator setting the env var.
    //   • http://localhost:5173 / 3000 — local dev (Vite / Next).
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', 'https://app.teamcore.space')),

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
