<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Billing driver
    |--------------------------------------------------------------------------
    |
    | Selects the implementation the WorkspaceBillingController delegates
    | to. Options:
    |
    | - "none"    — production default until billing is wired. Every
    |               endpoint returns 501 billing_not_configured.
    | - "sandbox" — synchronous in-memory test driver that exercises
    |               the full lifecycle without hitting any third party.
    |               NEVER enable in production.
    | - "paddle"  — Paddle Billing integration (cloud edition only). Set
    |               PADDLE_ENV=sandbox while testing against Paddle's
    |               sandbox environment; flip to PADDLE_ENV=production
    |               once verified. The driver class lives under
    |               app/Modules/Cloud and is only bound on the cloud
    |               edition — self-hosted falls back to NullBillingDriver
    |               even when BILLING_DRIVER=paddle.
    |
    */
    'driver' => env('BILLING_DRIVER', 'none'),

    'sandbox' => [
        // Cache TTL for a sandbox checkout session, in seconds. The
        // session carries the tier/seats the admin chose at checkout-
        // time; completing it within the TTL applies the change.
        'session_ttl' => 3600,
    ],

    'paddle' => [
        // 'sandbox' targets https://sandbox-api.paddle.com.
        // 'production' targets https://api.paddle.com.
        // Kept separate from APP_ENV so a staging deployment can still
        // hit prod-Paddle if that's where its data lives.
        'env' => env('PADDLE_ENV', 'sandbox'),

        // Paddle API key (`pdl_*`). Required for any outbound call.
        'api_key' => env('PADDLE_API_KEY', ''),

        // Notification secret used to HMAC every Paddle-Signature
        // header on incoming webhooks. Generated in the Paddle
        // dashboard when the webhook destination is created — open
        // the destination's detail page in Developer Tools →
        // Notifications, reveal the "Secret key" field.
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET', ''),

        // Explicit opt-out of webhook signature verification for
        // bring-up environments where the dashboard hasn't yet
        // surfaced a secret. Defaults to TRUE so an unset env var
        // means "verify" — forgetting to set this in production
        // does NOT silently disable the integrity check.
        //
        // While disabled, webhooks are accepted on body parse alone.
        // Any caller that can POST to /api/v1/billing/webhook can
        // mutate workspace billing state. Acceptable for sandbox
        // bring-up; never acceptable in production.
        'verify_webhooks' => filter_var(
            env('PADDLE_WEBHOOK_VERIFY', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        ) ?? true,

        // PlanTier value → Paddle price ID. Sandbox and production
        // each have their own catalog; switch the env vars (not the
        // structure) when promoting. Free is excluded (no money
        // changing hands); SelfHosted has its own price entry now
        // because subscribing to the self-hosted plan auto-issues a
        // license token tied to the resulting Paddle subscription.
        'prices' => [
            'entrepreneur' => env('PADDLE_PRICE_ENTREPRENEUR', ''),
            'team' => env('PADDLE_PRICE_TEAM', ''),
            'self_hosted' => env('PADDLE_PRICE_SELF_HOSTED', ''),
        ],

        // Outbound HTTP timeout for the Paddle REST API. Paddle's
        // sandbox can be slower than prod — keep this generous.
        'http_timeout_seconds' => (int) env('PADDLE_HTTP_TIMEOUT', 15),

        // Replay-protection window for the `ts` field on the
        // Paddle-Signature header. Paddle's own SDK helpers default
        // to 5 seconds; we match that. Bump (e.g. 30) if you see
        // legitimate webhooks getting rejected as stale due to your
        // server-side processing latency.
        'signature_tolerance_seconds' => (int) env('PADDLE_SIGNATURE_TOLERANCE', 5),
    ],
];
