<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API
    |--------------------------------------------------------------------------
    |
    | API token generated at https://painel.autentique.com.br/perfil/api.
    |
    */

    'token' => env('AUTENTIQUE_TOKEN'),

    'url' => env('AUTENTIQUE_URL', 'https://api.autentique.com.br/v2/graphql'),

    'timeout' => (int) env('AUTENTIQUE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Sandbox
    |--------------------------------------------------------------------------
    |
    | When enabled, documents are created with `sandbox: true` (no credits
    | consumed, deleted by Autentique after a few days) and listings include
    | sandbox documents.
    |
    */

    'sandbox' => (bool) env('AUTENTIQUE_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Rate limit
    |--------------------------------------------------------------------------
    |
    | Autentique allows 60 requests per minute per user and answers 429
    | "Too Many Attempts." above that. Requests are throttled client-side
    | through the cache store (shared by every worker using the same store);
    | when the window is full the client waits up to `max_wait` seconds for
    | a free slot, otherwise it throws a RateLimitException.
    |
    | A 429 that still reaches the API is retried `retry_times` times,
    | honouring the Retry-After header when present.
    |
    */

    'rate_limit' => [
        'enabled' => (bool) env('AUTENTIQUE_RATE_LIMIT_ENABLED', true),
        'max_attempts' => (int) env('AUTENTIQUE_RATE_LIMIT_MAX_ATTEMPTS', 60),
        'decay_seconds' => (int) env('AUTENTIQUE_RATE_LIMIT_DECAY_SECONDS', 60),
        'max_wait' => (int) env('AUTENTIQUE_RATE_LIMIT_MAX_WAIT', 60),
        'cache_store' => env('AUTENTIQUE_RATE_LIMIT_CACHE_STORE'),
        'retry_times' => (int) env('AUTENTIQUE_RETRY_TIMES', 3),
        'retry_sleep_ms' => (int) env('AUTENTIQUE_RETRY_SLEEP_MS', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | The webhook route is registered only when a secret is set. Every request
    | must carry a valid HMAC-SHA256 `X-Autentique-Signature` header; valid
    | payloads dispatch JeffersonGoncalves\Autentique\Events\WebhookReceived.
    |
    */

    'webhook' => [
        'secret' => env('AUTENTIQUE_WEBHOOK_SECRET'),
        'path' => env('AUTENTIQUE_WEBHOOK_PATH', 'autentique/webhook'),
        'middleware' => [],
    ],

];
