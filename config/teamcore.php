<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Edition
    |--------------------------------------------------------------------------
    |
    | Which edition of the product this Laravel process is running. The
    | self-hosted module split (see ARCHITECTURE.md §5) will toggle the
    | route file, console commands, and plan-cap enforcer binding
    | from a single env switch. For now we expose the value on
    | the /me response so the desktop client can hide cloud-only UI on
    | self-hosted installs even before the split lands.
    |
    */
    'edition' => env('TC_EDITION', 'cloud'),

    /*
    |--------------------------------------------------------------------------
    | IP-hash pepper (audit M6)
    |--------------------------------------------------------------------------
    |
    | Server-side secret mixed into every persisted IP hash (share-link
    | views, waitlist, feedback, license verify pings) so a DB leak
    | alone cannot reverse the 2^32 IPv4 space via rainbow table.
    | See {@see \App\Support\IpHasher}.
    */
    'ip_hash_pepper' => env('TC_IP_HASH_PEPPER', ''),

    /*
    |--------------------------------------------------------------------------
    | Per-user limits (audit M11)
    |--------------------------------------------------------------------------
    |
    | Defensive caps that apply equally to free and paid tiers — they
    | sit *below* the per-workspace plan caps and exist to deter
    | resource exhaustion by a single registered user.
    */
    'limits' => [
        'max_workspaces_per_user' => (int) env('TC_MAX_WORKSPACES_PER_USER', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature flags (edition-derived)
    |--------------------------------------------------------------------------
    |
    | Per-edition flag overrides surfaced in /auth/me.feature_flags. The
    | desktop client reads these to decide which menu items to render —
    | billing UI on cloud only, share-link sheets only when the toggle
    | is on, etc. Self-hosted admins flip SELFHOST_SHARE_LINKS_ENABLED
    | and SELFHOST_FEEDBACK_REMOTE off to fence those features.
    |
    */
    'features' => [
        'share_links_enabled' => env('SELFHOST_SHARE_LINKS_ENABLED', true),
        'feedback_remote' => env('SELFHOST_FEEDBACK_REMOTE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Share viewer
    |--------------------------------------------------------------------------
    |
    | `host` lets cloud run the share UI on a dedicated subdomain
    | (share.usework.space). When set, the ShareHostOnly middleware
    | refuses non-share routes on that host so an API request hitting
    | that vhost gets a 404 even if DNS is misconfigured. Leave empty
    | on self-hosted — the UI then lives on the same domain as the API.
    |
    | `api_base` is injected into the Blade shell as
    | window.__TC_SHARE_API_BASE__. Empty = same-origin (self-hosted).
    | On cloud, set this to the API host (https://api.teamcore.space)
    | so the share-domain bundle calls back home.
    |
    */
    'share' => [
        'host' => env('SHARE_UI_HOST', ''),
        'api_base' => env('SHARE_UI_API_BASE', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | License signing
    |--------------------------------------------------------------------------
    |
    | The cloud edition holds the Ed25519 private key in env (base64-
    | encoded 64-byte sodium secret key). The public key is committed
    | in-repo at licensing/public_key.pem so the self-hosted image can
    | bake it in at build time. Generate a fresh keypair with:
    |
    |     php artisan tc:license:keygen
    |
    | The command writes the public key to licensing/public_key.pem and
    | prints the private key — copy it into env as
    | LICENSE_SIGNING_PRIVATE_KEY.
    |
    */
    'license' => [
        'signing_private_key' => env('LICENSE_SIGNING_PRIVATE_KEY'),
        'verifying_public_key_path' => env(
            'LICENSE_VERIFYING_PUBLIC_KEY_PATH',
            base_path('licensing/public_key.pem'),
        ),
        // Verify-endpoint throttle, configurable for ops. 60/hour per
        // IP per the spec — set tighter if abuse is observed.
        'verify_throttle' => env('LICENSE_VERIFY_THROTTLE', '60,60'),
        // Claim-endpoint throttle. Tighter than verify: this is a
        // token-minting endpoint, so brute-forcing the short claim
        // code must stay expensive. 10/hour per IP by default.
        'claim_throttle' => env('LICENSE_CLAIM_THROTTLE', '10,60'),
    ],

];
