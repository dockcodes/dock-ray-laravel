<?php

return [
    'token' => env('RAY_TOKEN'),

    'private_key' => env('RAY_PRIVATE_KEY'),

    'url' => env('RAY_URL', 'https://dockray.io'),

    'environment' => env('RAY_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('RAY_RELEASE'),

    'traces_sample_rate' => (float) env('RAY_TRACES_SAMPLE_RATE', 0),

    'sample_rate' => (float) env('RAY_SAMPLE_RATE', 1),

    'send_default_pii' => (bool) env('RAY_SEND_DEFAULT_PII', false),

    'send_default_user' => (bool) env('RAY_SEND_DEFAULT_USER', false),

    'ignore_exceptions' => [],

    'ignore_transactions' => [
        'horizon*',
        'telescope*',
        '_debugbar*',
    ],

    'options' => [
        'max_request_body_size' => env('RAY_MAX_REQUEST_BODY_SIZE', 'none'),
        'attach_stacktrace' => (bool) env('RAY_ATTACH_STACKTRACE', false),
        'enable_compression' => (bool) env('RAY_ENABLE_COMPRESSION', false),
    ],
];
