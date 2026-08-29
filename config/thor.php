<?php

return [
    'token' => env('THOR_TOKEN'),

    'private_key' => env('THOR_PRIVATE_KEY'),

    'url' => env('THOR_URL', 'https://thor.dock.codes'),

    'environment' => env('THOR_ENVIRONMENT', env('APP_ENV', 'production')),

    'release' => env('THOR_RELEASE'),

    /*
     | Ułamek żądań, dla których powstaje transakcja. 0 wyłącza pomiar ruchu
     | i zostawia samo raportowanie błędów.
     */
    'traces_sample_rate' => (float) env('THOR_TRACES_SAMPLE_RATE', 0),

    'sample_rate' => (float) env('THOR_SAMPLE_RATE', 1),

    /*
     | Dane osobowe użytkownika (identyfikator, e-mail, adres IP, user agent)
     | jadą do panelu tylko po jawnym włączeniu.
     */
    'send_default_pii' => (bool) env('THOR_SEND_DEFAULT_PII', false),

    'send_default_user' => (bool) env('THOR_SEND_DEFAULT_USER', true),

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
        'max_request_body_size' => env('THOR_MAX_REQUEST_BODY_SIZE', 'medium'),
        'attach_stacktrace' => (bool) env('THOR_ATTACH_STACKTRACE', false),
    ],
];
