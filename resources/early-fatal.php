<?php

/**
 * DockRay early fatal error reporter for Laravel (and any plain PHP application).
 *
 * Load it with `auto_prepend_file` (php.ini, `.user.ini` or the PHP-FPM pool):
 *
 *     auto_prepend_file=/absolute/path/to/vendor/dockcodes/dock-ray-laravel/resources/early-fatal.php
 *
 * The bridge reports errors once the application has booted. A fatal error
 * raised before that - a parse error in `bootstrap/app.php`, a broken
 * vendor file, memory exhausted while providers register - never reaches it.
 * This file catches exactly those and sends one event to
 * `POST {RAY_URL}/api/v1/{RAY_TOKEN}/project`.
 *
 * Deliberately dependency-free and PHP 7.4 compatible: it runs before
 * Composer's autoloader and must work when that is what is broken. It reads
 * `RAY_TOKEN`, `RAY_PRIVATE_KEY`, `RAY_URL` (default https://dockray.io),
 * `RAY_ENVIRONMENT` and `RAY_RELEASE` from the server environment, falling
 * back to what Laravel loaded from `.env` when the error happened after that.
 *
 * It stays silent when the bridge (any DockRay SDK client with its error
 * listeners) is already up - that one reports the error itself - and it never
 * throws or prints anything. Everything lives inside a closure: a prepended
 * file runs in the global scope, and a stray variable here would become a
 * global of the application.
 */

if (defined('DOCKRAY_EARLY_FATAL')) {
    return;
}

define('DOCKRAY_EARLY_FATAL', '1.4.0');

(static function (): void {
    $version = DOCKRAY_EARLY_FATAL;

    // Memory for the report when the fatal error is "Allowed memory size exhausted".
    $reserved = str_repeat(' ', 65536);

    // vendor/dockcodes/dock-ray-laravel/resources -> the application root, for relative frame paths.
    $root = basename(dirname(__DIR__, 3)) === 'vendor' ? dirname(__DIR__, 4) : '';

    register_shutdown_function(static function () use (&$reserved, $root, $version): void {
        $reserved = null;

        try {
            $error = error_get_last();
            $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

            if (! is_array($error) || ! (((int) $error['type']) & $fatal)) {
                return;
            }

            if (stripos((string) $error['message'], 'Allowed memory size') === 0) {
                @ini_set('memory_limit', (string) (memory_get_usage(true) + 16777216));
            }

            // Registered from a shutdown function, this one runs after every other
            // shutdown function: the framework renders its error page and the SDK,
            // if it is up, reports first. Only then is it known whether anyone did.
            register_shutdown_function(static function () use ($error, $root, $version): void {
                set_error_handler(static function (): bool {
                    return true;
                });

                try {
                    $env = static function (string $name): string {
                        $value = getenv($name);

                        if (! is_string($value) || $value === '') {
                            $value = $_SERVER[$name] ?? ($_ENV[$name] ?? '');
                        }

                        return is_string($value) ? trim($value) : '';
                    };

                    $type = (int) $error['type'];

                    // The bridge's client reports fatal errors through its own listeners.
                    if (class_exists('Dock\Ray\RaySdk', false)) {
                        try {
                            $hub = \Dock\Ray\RaySdk::getCurrentHub();
                            $client = $hub->getClient();
                            $listener = $type & (E_USER_ERROR | E_RECOVERABLE_ERROR)
                                ? 'Dock\Ray\Integration\ErrorListenerIntegration'
                                : 'Dock\Ray\Integration\FatalErrorListenerIntegration';

                            if ($client !== null && $client->getOptions()->getAuthData() !== null && $hub->getIntegration($listener) !== null) {
                                return;
                            }
                        } catch (\Throwable $exception) {
                            // An SDK in an unknown state is no reason to stay silent.
                        }
                    }

                    $token = $env('RAY_TOKEN');
                    $key = $env('RAY_PRIVATE_KEY');
                    $url = rtrim($env('RAY_URL') ?: 'https://dockray.io', '/');
                    $parts = parse_url($url);

                    if ($token === '' || $key === '' || preg_match('/[\x00-\x20\x7f]/', $token.$key) || ! is_array($parts) || ! isset($parts['host'])) {
                        return;
                    }

                    // The key travels in a header: plain HTTP only to this machine, for local development.
                    $scheme = strtolower($parts['scheme'] ?? '');

                    if ($scheme !== 'https' && ! ($scheme === 'http' && in_array(strtolower(trim($parts['host'], '[]')), ['localhost', '127.0.0.1', '::1'], true))) {
                        return;
                    }

                    $levels = [
                        E_ERROR => 'Error', E_PARSE => 'Parse Error', E_CORE_ERROR => 'Core Error',
                        E_COMPILE_ERROR => 'Compile Error', E_USER_ERROR => 'User Error', E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
                    ];
                    $message = (string) $error['message'];
                    $relative = static function (string $file) use ($root): string {
                        return $root !== '' && strncmp($file, $root.'/', strlen($root) + 1) === 0 ? substr($file, strlen($root) + 1) : $file;
                    };
                    $frame = static function (string $file, int $line) use ($relative): array {
                        $path = $relative($file);

                        return ['filename' => $path, 'lineno' => $line, 'in_app' => strncmp($path, 'vendor/', 7) !== 0];
                    };

                    // Same type and value as the SDK's fatal error handler, so both land on one issue.
                    $exceptionType = 'Dock\Ray\Exception\FatalErrorException';
                    $value = ($levels[$type] ?? 'Error').': '.$message;
                    $frames = [];

                    if (strncmp($message, 'Uncaught ', 9) === 0
                        && preg_match('/^Uncaught ([^\s:]+)(?:: (.*?))? in (\S.*?):(\d+)(?:\R+Stack trace:\R(.*?))?(?:\R+\s*thrown)?\s*$/s', $message, $uncaught) === 1) {
                        $exceptionType = $uncaught[1];
                        $value = isset($uncaught[2]) && $uncaught[2] !== '' ? $uncaught[2] : $uncaught[1];

                        if (preg_match_all('/^#\d+ (\S.*?)\((\d+)\): /m', $uncaught[5] ?? '', $calls, PREG_SET_ORDER)) {
                            foreach (array_reverse(array_slice($calls, 0, 49)) as $call) {
                                $frames[] = $frame($call[1], (int) $call[2]);
                            }
                        }
                    }

                    $frames[] = $frame((string) $error['file'], (int) $error['line']);
                    $value = function_exists('mb_substr') ? mb_substr($value, 0, 1024) : substr($value, 0, 1024);

                    // One report per error per ten seconds: a fatal in the bootstrap hits every request.
                    $marker = rtrim(sys_get_temp_dir(), '/\\').'/dockray-early-'.substr(sha1($token.'|'.$exceptionType.'|'.$value.'|'.$error['file'].':'.$error['line']), 0, 20);
                    $last = @filemtime($marker);

                    if ($last !== false && time() - $last < 10) {
                        return;
                    }

                    @touch($marker);

                    $event = [
                        'event_id' => bin2hex(random_bytes(16)),
                        'timestamp' => microtime(true),
                        'platform' => 'php',
                        'level' => 'fatal',
                        'logger' => 'php',
                        'sdk' => ['name' => 'dockray.laravel.early', 'version' => $version],
                        'environment' => $env('RAY_ENVIRONMENT') ?: ($env('APP_ENV') ?: 'production'),
                        'contexts' => ['runtime' => ['name' => 'php', 'version' => PHP_VERSION]],
                        'exception' => ['values' => [[
                            'type' => $exceptionType,
                            'value' => $value,
                            'stacktrace' => ['frames' => $frames],
                            'mechanism' => ['type' => 'generic', 'handled' => false],
                        ]]],
                    ];

                    if ($env('RAY_RELEASE') !== '') {
                        $event['release'] = $env('RAY_RELEASE');
                    }

                    if (function_exists('php_uname')) {
                        $event['contexts']['os'] = ['name' => (string) @php_uname('s'), 'version' => (string) @php_uname('r')];
                    }

                    // URL without the query string: it may carry tokens.
                    if (PHP_SAPI !== 'cli' && isset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'])) {
                        $https = ! empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
                        $path = strtok((string) $_SERVER['REQUEST_URI'], '?');
                        $event['request'] = [
                            'url' => substr(preg_replace('/[\x00-\x1F\x7F]/', '', ($https ? 'https' : 'http').'://'.$_SERVER['HTTP_HOST'].($path === false ? '/' : $path)) ?? '', 0, 2048),
                            'method' => substr(preg_replace('/[^A-Z]/', '', strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))) ?? '', 0, 16),
                        ];
                    }

                    $body = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

                    if (! is_string($body)) {
                        return;
                    }

                    // The visitor already has whatever page there was; they do not wait for DockRay.
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }

                    $endpoint = $url.'/api/v1/'.rawurlencode($token).'/project';
                    $headers = [
                        'Authorization: Bearer '.$key,
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'User-Agent: dockray-laravel-early/'.$version,
                    ];

                    if (function_exists('curl_init')) {
                        $handle = curl_init($endpoint);

                        if ($handle !== false) {
                            curl_setopt_array($handle, [
                                CURLOPT_POST => true,
                                CURLOPT_POSTFIELDS => $body,
                                CURLOPT_HTTPHEADER => $headers,
                                CURLOPT_RETURNTRANSFER => true,
                                CURLOPT_CONNECTTIMEOUT => 2,
                                CURLOPT_TIMEOUT => 3,
                                CURLOPT_NOSIGNAL => true,
                                CURLOPT_FOLLOWLOCATION => false,
                                CURLOPT_SSL_VERIFYPEER => true,
                                CURLOPT_SSL_VERIFYHOST => 2,
                                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                            ]);
                            curl_exec($handle);
                            // No curl_close(): a no-op since PHP 8.0 and deprecated since 8.5.
                            unset($handle);
                        }

                        return;
                    }

                    $previousTimeout = ini_get('default_socket_timeout');
                    @ini_set('default_socket_timeout', '2');
                    @file_get_contents($endpoint, false, stream_context_create([
                        'http' => [
                            'method' => 'POST',
                            'header' => implode("\r\n", $headers),
                            'content' => $body,
                            'timeout' => 3,
                            'ignore_errors' => true,
                            'follow_location' => 0,
                        ],
                        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                    ]));
                    @ini_set('default_socket_timeout', (string) $previousTimeout);
                } catch (\Throwable $exception) {
                    // Reporting a fatal error must not add one.
                } finally {
                    restore_error_handler();
                }
            });
        } catch (\Throwable $exception) {
            // Same: never a second error from the reporter.
        }
    });
})();
