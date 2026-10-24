<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Security;

use Dock\Ray\AuthData;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Opt-in security report: integrity of the application files and
 * security-relevant settings, sent to `POST /api/v1/{token}/security`.
 *
 * Call from the scheduler or the console, never while a page renders. The
 * body carries hashes, sizes, relative paths and booleans only - no file
 * contents, absolute paths, users or configuration values. Contract:
 * `docs/security-report.md` in DockRay.
 *
 * State lives in a private directory: the schedule and the last result in
 * `state.json` (also the lock), the per-file hash cache and the upload scan
 * position in `hashes.json`. The posture measured in web requests goes to the
 * application cache, which the web server and the scheduler both reach even
 * when they run as different users.
 */
final class SecurityReporter
{
    public const AGENT = 'laravel';

    /** Cache key of the posture measured during a web request. */
    public const WEB_POSTURE_KEY = 'dockray.security.web_posture';

    /** Web-request posture older than this is not reported from the console any more. */
    private const WEB_POSTURE_TTL = 2592000;

    private const WEB_POSTURE_REFRESH = 3600;

    /** Posture keys that depend on the SAPI, its php.ini and the process user. */
    private const RUNTIME_POSTURE = ['code_writable', 'display_errors', 'exec_available'];

    private const DEFAULT_INTERVAL = 21600;

    private const MIN_INTERVAL = 3600;

    private const MAX_INTERVAL = 86400;

    /** A deploy is reported once nothing changed for this long. */
    private const DEBOUNCE = 60;

    private const RETRY_AFTER_FAILURE = 300;

    private const MAX_BODY_BYTES = 2097152;

    private const MAX_RESPONSE_BYTES = 262144;

    private const MAX_ITEMS = 50;

    private const MAX_GROUPS = 1500;

    private const MAX_FILES = 500;

    private const MAX_SUSPICIOUS = 500;

    /** Contract suggestion: stop at 200 000 files or 20 s. */
    private const DEFAULT_TIME_LIMIT = 20.0;

    private const DEFAULT_MAX_FILES = 200000;

    /** Names walked in the upload directories before the scan counts as truncated. */
    private const MAX_UPLOAD_ENTRIES = 300000;

    private const SEVERITIES = ['critical', 'high', 'medium', 'low', 'unknown'];

    /** Sensitive single files and their roles (contract: `files`, Laravel column). */
    private const SENSITIVE_FILES = [
        'public/index.php' => 'entry',
        'artisan' => 'entry',
        '.env' => 'config',
        'bootstrap/app.php' => 'config',
        'public/.htaccess' => 'server',
        'public/.user.ini' => 'server',
    ];

    private const UPLOAD_DIRECTORIES = ['storage/app/public', 'public/storage'];

    /** Debugging tools that should not be installed in production. */
    private const DEBUG_TOOLS = [
        'Spatie\LaravelIgnition\IgnitionServiceProvider',
        'Laravel\Telescope\TelescopeServiceProvider',
        'Barryvdh\Debugbar\ServiceProvider',
    ];

    private ?AuthData $auth;

    private bool $enabled;

    private string $basePath;

    private string $stateDirectory;

    private ?string $siteUrl;

    private ?string $release;

    private bool $debug;

    private ?string $platformVersion;

    private string $agentVersion;

    private float $timeLimit;

    private int $maxFiles;

    private bool $console;

    private HttpClientInterface $http;

    private LoggerInterface $logger;

    private ?CacheRepository $cache;

    /**
     * @param array{site_url?: string|null, release?: string|null, debug?: bool, platform_version?: string|null, agent_version?: string, time_limit?: float|int, max_files?: int, console?: bool} $options
     */
    public function __construct(?AuthData $auth, bool $enabled, string $basePath, string $stateDirectory,
        array $options = [], ?HttpClientInterface $http = null, ?LoggerInterface $logger = null, ?CacheRepository $cache = null)
    {
        $this->auth = $auth;
        $this->enabled = $enabled;
        $this->basePath = rtrim($basePath, '/\\');
        $this->stateDirectory = $stateDirectory;
        $this->siteUrl = isset($options['site_url']) && is_string($options['site_url']) ? $options['site_url'] : null;
        $this->release = isset($options['release']) && is_string($options['release']) && trim($options['release']) !== '' ? trim($options['release']) : null;
        $this->debug = (bool) ($options['debug'] ?? false);
        $this->platformVersion = isset($options['platform_version']) && is_string($options['platform_version']) ? $options['platform_version'] : null;
        $this->agentVersion = (string) ($options['agent_version'] ?? '');
        $this->timeLimit = max(0.0, (float) ($options['time_limit'] ?? self::DEFAULT_TIME_LIMIT));
        $this->maxFiles = max(0, (int) ($options['max_files'] ?? self::DEFAULT_MAX_FILES));
        $this->console = (bool) ($options['console'] ?? in_array(PHP_SAPI, ['cli', 'phpdbg'], true));
        $this->http = $http ?? HttpClient::create();
        $this->logger = $logger ?? new NullLogger;
        $this->cache = $cache;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Scans and sends when due: on the regular interval (the server's
     * `next_report_in`, 1 h to 24 h), a minute after a deploy changed
     * `composer.lock` or the release, or when forced. `--force` still
     * respects the retry backoff.
     *
     * Returns a sanitized result only - never keys, paths or the payload.
     *
     * @return array<string, mixed>
     */
    public function report(bool $force = false): array
    {
        if (! $this->enabled) {
            return ['status' => 'disabled'];
        }

        if ($this->auth === null) {
            return ['status' => 'not_configured'];
        }

        if (strtolower($this->auth->getScheme()) !== 'https' || preg_match('/[\x00-\x20\x7f]/', $this->auth->getPrivateKey())) {
            return ['status' => 'invalid_configuration'];
        }

        $lock = null;

        try {
            $lock = $this->lock();

            if ($lock === null) {
                return ['status' => 'busy'];
            }

            $state = $this->readState($lock);
            $now = time();

            if ((int) ($state['retry_at'] ?? 0) > $now) {
                return ['status' => 'backoff'];
            }

            $fingerprint = $this->fingerprint();

            // A deploy starts the debounce; the report waits until it settled.
            if (($state['seen'] ?? null) !== $fingerprint) {
                $state['seen'] = $fingerprint;
                $state['seen_at'] = $now;
            }

            $changed = ($state['reported'] ?? null) !== $fingerprint && $now - (int) ($state['seen_at'] ?? 0) >= self::DEBOUNCE;

            if (! $force && ! $changed && (int) ($state['next_at'] ?? 0) > $now) {
                $this->saveState($lock, $state);

                return ['status' => 'not_due'];
            }

            // Persist a retry gate before the scan and the request, so a killed
            // process cannot turn every scheduler tick into a new report.
            $state['retry_at'] = $now + self::RETRY_AFTER_FAILURE;
            $this->saveState($lock, $state);

            $payload = $this->payload($now);
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            if (! is_string($body) || strlen($body) > self::MAX_BODY_BYTES) {
                $state['retry_at'] = $now + self::MIN_INTERVAL;
                $this->saveState($lock, $state);

                return ['status' => 'too_large'];
            }

            $truncated = (bool) $payload['scan']['truncated'];
            $response = $this->http->request('POST', $this->auth->getServerUrl().'/api/v1/'.rawurlencode($this->auth->getToken()).'/security', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->auth->getPrivateKey(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'dockray-'.self::AGENT.'/'.$this->agentVersion,
                ],
                'body' => $body, 'max_redirects' => 0, 'verify_peer' => true, 'verify_host' => true,
                'timeout' => 5, 'max_duration' => 20, 'buffer' => false,
            ]);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                $headers = $response->getHeaders(false);
                $response->cancel();
                $retry = (int) ($headers['retry-after'][0] ?? 0);
                $state['retry_at'] = $now + min(self::MAX_INTERVAL, max(in_array($status, [401, 403, 404, 413, 429], true) ? self::MIN_INTERVAL : self::RETRY_AFTER_FAILURE, $retry));
                $state['last'] = ['at' => $now, 'status' => 'failed', 'http_status' => $status];
                $this->saveState($lock, $state);

                return ['status' => 'failed', 'http_status' => $status];
            }

            $contents = '';

            foreach ($this->http->stream($response) as $chunk) {
                $contents .= $chunk->getContent();

                if (strlen($contents) > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();

                    throw new \RuntimeException('Security report response exceeds the limit.');
                }
            }

            $result = json_decode($contents, true);

            if (! is_array($result) || ($result['success'] ?? false) !== true) {
                throw new \RuntimeException('Security report response is invalid.');
            }

            $interval = isset($result['next_report_in']) && is_numeric($result['next_report_in'])
                ? (int) $result['next_report_in']
                : self::DEFAULT_INTERVAL;
            $interval = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
            $outcome = [
                'status' => 'sent',
                'truncated' => $truncated,
                'findings' => $this->findings($result['findings'] ?? []),
                'items' => $this->items($result['items'] ?? []),
            ];

            // A truncated scan continues from the hash cache within the hour
            // instead of waiting for the regular interval.
            $state = [
                'seen' => $fingerprint,
                'seen_at' => (int) ($state['seen_at'] ?? $now),
                'reported' => $fingerprint,
                'next_at' => $now + ($truncated ? self::MIN_INTERVAL : $interval),
                'retry_at' => 0,
                'last' => ['at' => $now] + $outcome,
            ];
            $this->saveState($lock, $state);

            return $outcome;
        } catch (Throwable $exception) {
            // Do not attach the exception: transport messages can include URLs or secrets.
            $this->logger->warning('DockRay security report could not be sent.');

            return ['status' => 'failed'];
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * The outcome of the last report, for the console. Server text in it is
     * already reduced to plain strings of bounded length.
     *
     * @return array<string, mixed>|null
     */
    public function last(): ?array
    {
        $raw = @file_get_contents($this->stateDirectory.'/state.json', false, null, 0, 262145);
        $state = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($state) && is_array($state['last'] ?? null) ? $state['last'] : null;
    }

    /**
     * The report body. Sections are only included when they were fully
     * evaluated - a section that is present is the complete state of what
     * it covers, and DockRay closes findings that are missing from it.
     *
     * @return array<string, mixed>
     */
    public function payload(?int $now = null): array
    {
        $now = $now ?? time();
        $started = microtime(true);
        $deadline = $started + $this->timeLimit;
        $cache = $this->readCache();
        $scanner = new SecurityScanner($this->basePath, is_array($cache['hashes'] ?? null) ? $cache['hashes'] : [], $deadline, $this->maxFiles);

        $files = [];

        foreach (self::SENSITIVE_FILES as $path => $role) {
            $entry = $scanner->file($path, $role);

            if ($entry !== null) {
                $files[] = $entry;
            }
        }

        // The web root is where a dropped script is reachable; Laravel ships one PHP file there.
        $suspicious = $scanner->rootPhp('public', ['index.php']);

        // Re-reading unchanged uploads gets half of the time per run and
        // continues from a cursor, so a huge media tree cannot starve the digests.
        $uploads = $scanner->uploads(
            $this->uploadDirectories(),
            is_array($cache['uploads'] ?? null) ? $cache['uploads'] : [],
            $now,
            $this->timeLimit / 2,
            min(self::MAX_UPLOAD_ENTRIES, $this->maxFiles)
        );

        foreach ($uploads['entries'] as $entry) {
            $suspicious[] = $entry;
        }

        $groups = [];

        foreach ($this->groupDefinitions() as $definition) {
            $group = $scanner->group(...$definition);

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        if (count($suspicious) > self::MAX_SUSPICIOUS) {
            $suspicious = array_slice($suspicious, 0, self::MAX_SUSPICIOUS);
            $scanner->markTruncated();
        }

        // Unlike `suspicious`, a missing group is not taken as fixed in a
        // truncated report - only completely hashed groups are listed.
        $this->writeCache(['hashes' => $scanner->cache(), 'uploads' => $uploads['state']]);

        $payload = [
            'schema' => 1,
            'platform' => 'laravel',
            'platform_version' => $this->platformVersion,
            'php_version' => PHP_VERSION,
            'agent_version' => $this->agentVersion,
            'scan' => [
                'files' => $scanner->files(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'truncated' => $scanner->truncated(),
            ],
            'groups' => array_slice($groups, 0, self::MAX_GROUPS),
            'files' => array_slice($files, 0, self::MAX_FILES),
            'suspicious' => $suspicious,
            'posture' => $this->posture(),
        ];

        $siteUrl = $this->validSiteUrl();

        if ($siteUrl !== null) {
            $payload['site_url'] = $siteUrl;
        }

        return $payload;
    }

    /**
     * Posture booleans that mean something in Laravel (contract: `posture`).
     *
     * `code_writable`, `display_errors` and `exec_available` depend on the
     * SAPI, its php.ini and the process user, so the scheduler's console
     * process says nothing about them: in the console they come from the
     * last web request (at most 30 days old) or are left out, and DockRay
     * keeps its earlier verdict. `debug_mode` and `debug_tools` are
     * configuration and installed classes - the same everywhere.
     *
     * @return array<string, bool>
     */
    public function posture(): array
    {
        $tools = false;

        foreach (self::DEBUG_TOOLS as $class) {
            if (class_exists($class)) {
                $tools = true;

                break;
            }
        }

        return ($this->console ? $this->storedWebPosture() : $this->runtimePosture()) + [
            'debug_mode' => $this->debug,
            'debug_tools' => $tools,
        ];
    }

    /**
     * Remembers the posture of a web request for the reports the scheduler
     * builds in the console. At most once an hour; the provider calls it
     * after the response was sent, for page views only.
     */
    public function captureWebPosture(): void
    {
        if (! $this->enabled || $this->console || $this->cache === null) {
            return;
        }

        try {
            $stored = $this->cache->get(self::WEB_POSTURE_KEY);

            if (is_array($stored) && time() - (int) ($stored['at'] ?? 0) < self::WEB_POSTURE_REFRESH) {
                return;
            }

            $this->cache->put(self::WEB_POSTURE_KEY, ['at' => time(), 'values' => $this->runtimePosture()], self::WEB_POSTURE_TTL);
        } catch (Throwable $exception) {
            // A cache outage only means the console report leaves these keys out.
        }
    }

    /** @return array<string, bool> */
    private function runtimePosture(): array
    {
        return [
            'code_writable' => is_writable($this->basePath.'/app'),
            'display_errors' => self::displayErrors(),
            'exec_available' => self::execAvailable(),
        ];
    }

    /** @return array<string, bool> */
    private function storedWebPosture(): array
    {
        try {
            $stored = $this->cache !== null ? $this->cache->get(self::WEB_POSTURE_KEY) : null;
        } catch (Throwable $exception) {
            return [];
        }

        if (! is_array($stored) || ! is_array($stored['values'] ?? null) || time() - (int) ($stored['at'] ?? 0) > self::WEB_POSTURE_TTL) {
            return [];
        }

        $values = [];

        foreach (self::RUNTIME_POSTURE as $key) {
            if (array_key_exists($key, $stored['values'])) {
                $values[$key] = (bool) $stored['values'][$key];
            }
        }

        return $values;
    }

    /**
     * `vendor` is versioned by `composer.lock`; `vendor/composer` and
     * `vendor/autoload.php` are left out because every `composer dump-autoload`
     * rewrites them (class map, install references) without a package change.
     * The application group needs a release to be versioned at all.
     *
     * @return list<array{0: string, 1: string, 2: string|null, 3: list<string>, 4: string, 5: list<string>}>
     */
    private function groupDefinitions(): array
    {
        $definitions = [];
        $lock = $this->basePath.'/composer.lock';

        if (is_dir($this->basePath.'/vendor') && is_file($lock)) {
            $version = @hash_file('sha256', $lock);

            if (is_string($version)) {
                $definitions[] = ['package', 'vendor', $version, ['vendor'], 'vendor/', ['vendor/composer/', 'vendor/autoload.php']];
            }
        }

        if ($this->release !== null) {
            $definitions[] = ['app', 'app', substr($this->release, 0, 64), ['app', 'bootstrap', 'config', 'routes', 'public'], '', ['bootstrap/cache/', 'public/storage/']];
        }

        return $definitions;
    }

    /**
     * `public/storage` is normally a symlink to `storage/app/public`; walking
     * both would list every upload twice.
     *
     * @return list<string>
     */
    private function uploadDirectories(): array
    {
        $directories = [];
        $seen = [];

        foreach (self::UPLOAD_DIRECTORIES as $directory) {
            $real = @realpath($this->basePath.'/'.$directory);

            if ($real === false || isset($seen[$real])) {
                continue;
            }

            $seen[$real] = true;
            $directories[] = $directory;
        }

        return $directories;
    }

    /** What changes with a deploy: the locked packages and the release. */
    private function fingerprint(): string
    {
        $lock = @hash_file('sha256', $this->basePath.'/composer.lock');

        return hash('sha256', (is_string($lock) ? $lock : '').'|'.($this->release ?? ''));
    }

    /**
     * Laravel switches `display_errors` off while it boots, so the current
     * value says nothing. The one this PHP started the request with decides
     * whether an error raised before that - a bootstrap fatal - is shown to
     * visitors.
     */
    private static function displayErrors(): bool
    {
        $all = function_exists('ini_get_all') ? @ini_get_all(null, true) : false;
        $value = is_array($all) && isset($all['display_errors']['global_value'])
            ? $all['display_errors']['global_value']
            : ini_get('display_errors');

        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true', 'stdout', 'stderr'], true);
    }

    private static function execAvailable(): bool
    {
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        foreach (['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen'] as $function) {
            if (function_exists($function) && is_callable($function) && ! in_array($function, $disabled, true)) {
                return true;
            }
        }

        return false;
    }

    private function validSiteUrl(): ?string
    {
        $url = $this->siteUrl;

        if ($url === null || $url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host'], $parts['scheme'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }

    /**
     * @param mixed $findings
     *
     * @return array<string, int>
     */
    private function findings($findings): array
    {
        $counts = [];

        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $value = is_array($findings) ? ($findings[$severity] ?? 0) : 0;
            $counts[$severity] = is_numeric($value) ? max(0, (int) $value) : 0;
        }

        return $counts;
    }

    /**
     * Server data is untrusted: only the expected fields, as plain strings of
     * bounded length without control characters. Escaping is the console's job.
     *
     * @param mixed $items
     *
     * @return list<array<string, string|null>>
     */
    private function items($items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];

        foreach (array_slice($items, 0, self::MAX_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $severity = strtolower(self::field($item, 'severity', 16) ?? 'unknown');
            $code = self::field($item, 'code', 64);

            $clean[] = [
                'code' => $code !== null && preg_match('/^[a-z0-9_]+$/', $code) === 1 ? $code : null,
                'severity' => in_array($severity, self::SEVERITIES, true) ? $severity : 'unknown',
                'subject' => self::field($item, 'subject', 255),
                'title' => self::field($item, 'title', 300),
                'url' => $this->safeLink(self::field($item, 'url', 500)),
            ];
        }

        return $clean;
    }

    /** @param array<mixed> $item */
    private static function field(array $item, string $key, int $max): ?string
    {
        if (! isset($item[$key]) || ! is_scalar($item[$key])) {
            return null;
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $item[$key]) ?? '');
        $value = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);

        return $value === '' ? null : $value;
    }

    /** A server-provided link is kept only when it is HTTPS on the configured DockRay host. */
    private function safeLink(?string $url): ?string
    {
        if ($url === null || $this->auth === null) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || strtolower($parts['host'] ?? '') !== strtolower($this->auth->getHost())
            || ($parts['port'] ?? null) !== $this->auth->getPort()) {
            return null;
        }

        return $url;
    }

    /** @return resource|null */
    private function lock()
    {
        $this->ensureDirectory();
        $file = $this->stateDirectory.'/state.json';

        if (is_link($file)) {
            throw new \RuntimeException('Invalid security state file.');
        }

        $handle = @fopen($file, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException('Security state is unavailable.');
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        if (! chmod($file, 0600)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            throw new \RuntimeException('Security state permissions could not be set.');
        }

        return $handle;
    }

    private function ensureDirectory(): void
    {
        if (! is_dir($this->stateDirectory) && ! @mkdir($this->stateDirectory, 0700, true) && ! is_dir($this->stateDirectory)) {
            throw new \RuntimeException('Security state is unavailable.');
        }

        if (is_link($this->stateDirectory) || (fileperms($this->stateDirectory) & 0077) !== 0) {
            throw new \RuntimeException('Security state directory must be private.');
        }
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>
     */
    private function readState($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle, 262145);
        $state = is_string($raw) && strlen($raw) <= 262144 ? json_decode($raw, true) : null;

        return is_array($state) ? $state : [];
    }

    /**
     * @param resource             $handle
     * @param array<string, mixed> $state
     */
    private function saveState($handle, array $state): void
    {
        $body = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($body)) {
            throw new \RuntimeException('Security state could not be encoded.');
        }

        rewind($handle);

        if (! ftruncate($handle, 0) || fwrite($handle, $body) !== strlen($body) || ! fflush($handle)) {
            throw new \RuntimeException('Security state could not be stored.');
        }
    }

    /** @return array<string, mixed> */
    private function readCache(): array
    {
        $file = $this->stateDirectory.'/hashes.json';

        if (is_link($file)) {
            return [];
        }

        $raw = @file_get_contents($file);
        $cache = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($cache) ? $cache : [];
    }

    /**
     * Written under the lock, through a temporary file and a rename, so a
     * killed process leaves the previous cache rather than half a file.
     *
     * @param array<string, mixed> $cache
     */
    private function writeCache(array $cache): void
    {
        $this->ensureDirectory();
        $file = $this->stateDirectory.'/hashes.json';
        $body = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if (! is_string($body) || is_link($file)) {
            return;
        }

        $temporary = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $body) !== strlen($body) || ! @chmod($temporary, 0600) || ! @rename($temporary, $file)) {
            @unlink($temporary);
        }
    }
}
