<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (Phase 16A — T16-01)
|--------------------------------------------------------------------------
|
| Native mobile clients are not subject to CORS; this only matters for a
| browser-based client (e.g. a Flutter web build) calling the API.
|
| Origins are an explicit allow-list. Empty (the default) means no browser
| origin may read API responses. Credentials are disabled because the API is
| token-based (Sanctum bearer tokens), not cookie-based.
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => false,

];
