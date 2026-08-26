<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_unique(array_filter([
        env('FRONTEND_URL'),                       // Production: https://ovms.widatra.com
        'https://ovms.widatra.com',
        'http://ovms.widatra.com',
        'https://ovmsdev.widatra.com',
        'http://ovmsdev.widatra.com',
        'http://ovmsdev.widatra.com:8282',
        'https://ovmsdev.widatra.com:8282',
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost:5175',
        'http://127.0.0.1:5173',
        'http://127.0.0.1:5174',
        'http://127.0.0.1:5175',
        'http://localhost:4173',
        'http://127.0.0.1:4173',
    ])),

    'allowed_origins_patterns' => [
        '#^https?://.*\.widatra\.com(:[0-9]+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
