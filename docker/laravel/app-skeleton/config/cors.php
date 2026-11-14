<?php

/**
 * CORS for the console API.
 *
 * The console is served same-origin through the proxy (scripts/serve.mjs), so
 * the safe default is no cross-origin access at all. Laravel's framework
 * default — which applied before this file existed — is `['*']`, and the
 * posture report flags that as `cors_restricted: fail`.
 *
 * Add origins only when a separate host calls the API, comma-separated:
 *   NVOOS_CORS_ORIGINS=https://console.example.com,https://staging.example.com
 */
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NVOOS_CORS_ORIGINS', '')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
