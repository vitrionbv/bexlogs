<?php

return [
    'production_url' => env('BEX_BASE_URL_PRODUCTION', 'https://app.bookingexperts.com'),
    'staging_url' => env('BEX_BASE_URL_STAGING', 'https://app.staging.bookingexperts.com'),

    /*
    |--------------------------------------------------------------------------
    | Pairing
    |--------------------------------------------------------------------------
    | How long a freshly-generated pairing token (shown to the user as a paste
    | code) remains valid before the extension must consume it.
    */
    'pairing_token_ttl_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Worker API
    |--------------------------------------------------------------------------
    | Bearer token the Node Playwright worker uses to talk back to Laravel.
    | MUST be set to a long random string in production.
    */
    'worker_api_token' => env('WORKER_API_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Browser extension
    |--------------------------------------------------------------------------
    */
    'extension_zip_path' => storage_path('app/public/bexlogs-extension.zip'),
    'extension_version' => env('EXTENSION_VERSION', '1.2.0'),

    /*
    | Minimum acceptable installed extension version. The frontend forces a
    | blocking "update required" modal if the running extension reports a
    | semver below this. Defaults to whatever Laravel ships with.
    */
    'extension_min_version' => env('EXTENSION_MIN_VERSION', env('EXTENSION_VERSION', '1.2.0')),
];
