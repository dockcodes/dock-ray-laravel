<?php

return [
    'token' => env('RAY_TOKEN'),

    'private_key' => env('RAY_PRIVATE_KEY'),

    'url' => env('RAY_URL', 'https://dockray.io'),

    'environment' => env('RAY_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('RAY_RELEASE'),

    /*
     | Ułamek żądań, dla których powstaje transakcja. 0 wyłącza pomiar ruchu
     | i zostawia samo raportowanie błędów.
     */
    'traces_sample_rate' => (float) env('RAY_TRACES_SAMPLE_RATE', 0),

    'sample_rate' => (float) env('RAY_SAMPLE_RATE', 1),

    /*
     | Dane osobowe użytkownika (identyfikator, e-mail, adres IP, user agent)
     | jadą do panelu tylko po jawnym włączeniu.
     */
    'send_default_pii' => (bool) env('RAY_SEND_DEFAULT_PII', false),

    'send_default_user' => (bool) env('RAY_SEND_DEFAULT_USER', true),

    /*
     | Wyjątki, których nie zgłaszamy — poza tymi, które Laravel i tak pomija
     | we własnej liście `dontReport`.
     */
    'ignore_exceptions' => [],

    /*
     | Ścieżki żądań pomijane przy pomiarze ruchu; wzorce jak w `Request::is()`.
     */
    'ignore_transactions' => [
        'horizon*',
        'telescope*',
        '_debugbar*',
    ],

    'options' => [
        'max_request_body_size' => env('RAY_MAX_REQUEST_BODY_SIZE', 'medium'),
        'attach_stacktrace' => (bool) env('RAY_ATTACH_STACKTRACE', false),
    ],
];
