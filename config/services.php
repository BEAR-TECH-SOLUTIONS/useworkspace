<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'exchange_rates' => [
        'key' => env('EXCHANGE_RATES_API_KEY'),
        'url' => env('EXCHANGE_RATES_API_URL', 'https://api.exchangeratesapi.io/v1/latest'),
        // exchangeratesapi.io's free tier locks the base to EUR; the
        // FxRateService still derives every cross-rate from that one
        // upstream call.
        'base' => env('EXCHANGE_RATES_BASE', 'EUR'),
        'symbols' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'EXCHANGE_RATES_SYMBOLS',
            'USD,GBP,RUB,JPY,CNY,CAD,AUD,CHF,INR',
        ))))),
    ],

];
