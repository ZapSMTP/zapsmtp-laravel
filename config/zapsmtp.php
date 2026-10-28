<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    | The API key (Dashboard -> API keys): ZAPSMTP_API_KEY, else ZAP_API_KEY.
    | base_url: null for https://api.zapsmtp.com/v1 (or ZAP_BASE_URL).
    | timeout: seconds per attempt. max_retries: retries of a request that is
    | safe to repeat (sends carry an Idempotency-Key, so they are).
    */

    'api_key' => env('ZAPSMTP_API_KEY', env('ZAP_API_KEY')),

    'base_url' => env('ZAPSMTP_BASE_URL', env('ZAP_BASE_URL')),

    'timeout' => env('ZAPSMTP_TIMEOUT', 60),

    'max_retries' => env('ZAPSMTP_MAX_RETRIES', 2),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    | Route::zapsmtpWebhooks() registers POST /zapsmtp/webhooks. Each request's
    | Zap-Signature is checked with the endpoint's secret (whsec_...), then
    | WebhookReceived and a typed event (EmailDelivered, EmailBounced, ...) are
    | dispatched for every event. `php artisan zapsmtp:webhook` creates the
    | endpoint and stores its secret.
    */

    'webhook' => [

        'secret' => env('ZAPSMTP_WEBHOOK_SECRET'),

        // oldest signature accepted, in seconds
        'tolerance' => env('ZAPSMTP_WEBHOOK_TOLERANCE', 300),

        // ZapSMTP retries until it gets a 2xx, so an event can arrive twice:
        // processed event ids are remembered in the cache and skipped
        'dedupe' => [
            'enabled' => true,
            'store' => env('ZAPSMTP_WEBHOOK_CACHE_STORE'), // null: the default cache store
            'ttl' => 60 * 60 * 24 * 3,                    // seconds; retries span about 28 hours
        ],

        // answer at once and dispatch the events from a queued job
        'queue' => [
            'enabled' => env('ZAPSMTP_WEBHOOK_QUEUE', false),
            'connection' => env('ZAPSMTP_WEBHOOK_QUEUE_CONNECTION'),
            'queue' => env('ZAPSMTP_WEBHOOK_QUEUE_NAME'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Suppression sync
    |--------------------------------------------------------------------------
    | When enabled, a bounce, a spam complaint or a send to a suppressed
    | address stamps the model whose email_column matches the recipient
    | (column = now()), so the app can stop emailing it. Needs the webhook
    | route and a nullable timestamp column (php artisan vendor:publish
    | --tag=zapsmtp-migrations adds users.email_suppressed_at).
    */

    'suppression' => [
        'enabled' => env('ZAPSMTP_SUPPRESSION_SYNC', false),
        'model' => 'App\\Models\\User',
        'email_column' => 'email',
        'column' => 'email_suppressed_at',
        'events' => ['email.bounced', 'email.complained', 'email.suppressed'],
    ],

];
