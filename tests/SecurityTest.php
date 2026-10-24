<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Tests;

use Dock\Ray\AuthData;
use Dock\Ray\Laravel\Console\ReportSecurity;
use Dock\Ray\Laravel\RayServiceProvider;
use Dock\Ray\Laravel\Security\SecurityReporter;
use Dock\Ray\Laravel\Security\SecurityScanner;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/dockray-laravel-security-test-'.bin2hex(random_bytes(8));
        $files = [
            'composer.lock' => '{"packages":[{"name":"laravel/framework"}]}',
            'vendor/autoload.php' => '<?php // generated',
            'vendor/composer/autoload_classmap.php' => '<?php return [];',
            'vendor/laravel/framework/src/Foundation/Application.php' => '<?php class Application {}',
            'vendor/laravel/framework/src/helpers.js' => 'console.log(1);',
            'vendor/laravel/framework/README.md' => 'not part of the digest',
            'app/Models/User.php' => '<?php class User {}',
            'bootstrap/app.php' => '<?php return 1;',
            'bootstrap/cache/config.php' => '<?php return [];',
            'config/app.php' => '<?php return [];',
            'routes/web.php' => '<?php',
            'public/index.php' => '<?php require __DIR__."/../vendor/autoload.php";',
            'public/.htaccess' => 'RewriteEngine On',
            'public/info.php' => '<?php phpinfo();',
            'artisan' => '#!/usr/bin/env php',
            '.env' => 'APP_KEY=base64:not-a-real-key',
            'storage/app/public/avatars/me.jpg' => "\xFF\xD8\xFF<?php system(\$_GET['c']); ?>",
            'storage/app/public/avatars/photo.png' => "\x89PNG plain image",
            'storage/app/public/index.php' => "<?php\n// Silence is golden.\n",
            'storage/app/public/2024/shell.php' => '<?php @eval($_POST["x"]);',
            'storage/app/public/.cache/x.txt' => 'hidden',
            'storage/app/public/.well-known/acme' => 'ok',
            'storage/app/public/media/.htaccess' => "AddHandler application/x-httpd-php .jpg\n",
            'storage/app/public/private/.htaccess' => "php_flag engine off\nDeny from all\n",
        ];

        foreach ($files as $path => $contents) {
            if (! is_dir(dirname($this->root.'/'.$path))) {
                mkdir(dirname($this->root.'/'.$path), 0700, true);
            }

            file_put_contents($this->root.'/'.$path, $contents);
        }
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
        parent::tearDown();
    }

    public function test_the_provider_schedules_the_security_report_only_when_enabled(): void
    {
        foreach ([false, true] as $enabled) {
            $app = new Application(sys_get_temp_dir());
            $app->instance('config', new Repository([
                'app' => ['url' => 'https://app.example.com'],
                'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
                'ray' => ['token' => null, 'security' => ['enabled' => $enabled]],
            ]));
            $app->register(CacheServiceProvider::class);
            $app->singleton(Schedule::class, function () {
                return new Schedule;
            });
            $app->register(RayServiceProvider::class);
            $app->boot();
            $events = $app->make(Schedule::class)->events();
            $this->assertCount($enabled ? 1 : 0, $events);
            if ($enabled) {
                $this->assertStringContainsString('ray:security', $events[0]->command);
                $this->assertSame('*/5 * * * *', $events[0]->expression);
                $this->assertTrue($events[0]->withoutOverlapping);
                $this->assertTrue($events[0]->runInBackground);
            }
            $this->assertSame($enabled, $app->make(SecurityReporter::class)->enabled());
            $app->flush();
        }
    }

    public function test_switched_off_or_unconfigured_it_scans_and_sends_nothing(): void
    {
        $http = new MockHttpClient(function () {
            $this->fail('Nothing may be sent while the security report is off.');
        });

        $off = new SecurityReporter($this->auth(), false, $this->root, $this->root.'/state', [], $http);
        $this->assertSame(['status' => 'disabled'], $off->report(true));

        $unconfigured = new SecurityReporter(null, true, $this->root, $this->root.'/state', [], $http);
        $this->assertSame(['status' => 'not_configured'], $unconfigured->report(true));

        $plain = new SecurityReporter(AuthData::create('project', 'secret', 'http://dockray.example'), true, $this->root, $this->root.'/state', [], $http);
        $this->assertSame(['status' => 'invalid_configuration'], $plain->report(true));

        $this->assertDirectoryDoesNotExist($this->root.'/state');
    }

    public function test_payload_follows_the_contract_and_carries_no_contents_or_absolute_paths(): void
    {
        $sent = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$sent) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://dockray.example/api/v1/project/security', $url);
            $this->assertContains('Authorization: Bearer secret', $options['headers']);
            $this->assertSame(0, $options['max_redirects']);
            $sent = $options['body'];

            return new MockResponse(json_encode([
                'success' => true,
                'findings' => ['critical' => 2, 'high' => '1', 'medium' => -4, 'low' => 'x'],
                'items' => [
                    ['code' => 'php_in_uploads', 'severity' => 'critical', 'subject' => "storage/app/public/2024/shell.php\x1b[31m", 'title' => '<fg=red>PHP in uploads</>', 'url' => 'https://dockray.example/panel/projects/1/security'],
                    ['code' => 'Bad Code!', 'severity' => 'apocalyptic', 'subject' => 'x', 'title' => 'y', 'url' => 'https://evil.example/phish'],
                ],
                'next_report_in' => 999999,
            ]));
        });

        // What an earlier page view measured; the console cannot measure it itself.
        $cache = new Cache(new ArrayStore);
        $cache->put(SecurityReporter::WEB_POSTURE_KEY, ['at' => time() - 600, 'values' => [
            'code_writable' => true, 'display_errors' => false, 'exec_available' => true,
        ]], 3600);
        $reporter = new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', [
            'site_url' => 'https://app.example.com', 'release' => 'v2.3.1', 'debug' => true,
            'platform_version' => '12.1.0', 'agent_version' => RayServiceProvider::VERSION, 'console' => true,
        ], $http, null, $cache);
        $before = time();
        $result = $reporter->report();

        $this->assertSame('sent', $result['status']);
        $this->assertFalse($result['truncated']);
        $this->assertSame(['critical' => 2, 'high' => 1, 'medium' => 0, 'low' => 0], $result['findings']);
        $this->assertSame('critical', $result['items'][0]['severity']);
        $this->assertStringNotContainsString("\x1b", $result['items'][0]['subject']);
        $this->assertSame('https://dockray.example/panel/projects/1/security', $result['items'][0]['url']);
        $this->assertNull($result['items'][1]['code']);
        $this->assertSame('unknown', $result['items'][1]['severity']);
        $this->assertNull($result['items'][1]['url']);

        $this->assertIsString($sent);
        $this->assertStringNotContainsString($this->root, $sent);
        $this->assertStringNotContainsString('not-a-real-key', $sent);
        $this->assertStringNotContainsString('system(', $sent);
        $this->assertStringNotContainsString('secret', $sent);

        $payload = json_decode($sent, true);
        $this->assertSame(1, $payload['schema']);
        $this->assertSame('laravel', $payload['platform']);
        $this->assertSame('12.1.0', $payload['platform_version']);
        $this->assertSame(RayServiceProvider::VERSION, $payload['agent_version']);
        $this->assertSame('https://app.example.com', $payload['site_url']);
        $this->assertFalse($payload['scan']['truncated']);
        $this->assertGreaterThan(0, $payload['scan']['files']);

        $groups = array_column($payload['groups'], null, 'type');
        $this->assertSame(['package', 'app'], array_keys($groups));
        $this->assertSame('vendor', $groups['package']['slug']);
        $this->assertSame(hash_file('sha256', $this->root.'/composer.lock'), $groups['package']['version']);
        // Generated autoloader files and non-code files are not part of the digest.
        $expected = [
            'laravel/framework/src/Foundation/Application.php' => hash('sha256', '<?php class Application {}'),
            'laravel/framework/src/helpers.js' => hash('sha256', 'console.log(1);'),
        ];
        $this->assertSame(2, $groups['package']['files']);
        $this->assertSame($this->digest($expected), $groups['package']['digest']);
        $this->assertSame('v2.3.1', $groups['app']['version']);
        // app, bootstrap (without bootstrap/cache), config, routes and public, .htaccess included.
        $this->assertSame(7, $groups['app']['files']);

        $files = array_column($payload['files'], 'role', 'path');
        $this->assertSame([
            'public/index.php' => 'entry', 'artisan' => 'entry', '.env' => 'config',
            'bootstrap/app.php' => 'config', 'public/.htaccess' => 'server',
        ], $files);
        $env = array_values(array_filter($payload['files'], static function (array $file): bool {
            return $file['path'] === '.env';
        }))[0];
        $this->assertSame(hash_file('sha256', $this->root.'/.env'), $env['sha256']);

        $suspicious = array_column($payload['suspicious'], null, 'path');
        ksort($suspicious);
        $this->assertSame([
            'public/info.php', 'storage/app/public/.cache', 'storage/app/public/2024/shell.php', 'storage/app/public/avatars/me.jpg',
            'storage/app/public/media/.htaccess',
        ], array_keys($suspicious));
        $this->assertSame('unknown_root_php', $suspicious['public/info.php']['kind']);
        $this->assertSame('hidden_directory', $suspicious['storage/app/public/.cache']['kind']);
        $this->assertSame('php_in_uploads', $suspicious['storage/app/public/2024/shell.php']['kind']);
        $this->assertSame(['eval', 'request_exec'], $suspicious['storage/app/public/2024/shell.php']['indicators']);
        $this->assertSame('php_in_media', $suspicious['storage/app/public/avatars/me.jpg']['kind']);
        // A handler that runs PHP in uploads is reported; `php_flag engine off` is not.
        $this->assertSame('suspicious_code', $suspicious['storage/app/public/media/.htaccess']['kind']);
        $this->assertSame(['php_handler'], $suspicious['storage/app/public/media/.htaccess']['indicators']);

        $this->assertSame(['code_writable', 'display_errors', 'exec_available', 'debug_mode', 'debug_tools'], array_keys($payload['posture']));
        $this->assertFalse($payload['posture']['display_errors']);
        $this->assertTrue($payload['posture']['debug_mode']);
        foreach ($payload['posture'] as $value) {
            $this->assertIsBool($value);
        }

        // The server's interval is clamped to 24 h; nothing more is due before that.
        $this->assertSame(['status' => 'not_due'], $reporter->report());
        $state = json_decode((string) file_get_contents($this->root.'/state/state.json'), true);
        $this->assertGreaterThanOrEqual($before + 86400, $state['next_at']);
        $this->assertLessThanOrEqual(time() + 86400, $state['next_at']);
        $this->assertSame(2, $reporter->last()['findings']['critical']);
        $this->assertSame(0700, fileperms($this->root.'/state') & 0777);
    }

    public function test_a_backslash_in_a_file_name_never_becomes_a_parent_directory_segment(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('On Windows a backslash separates directories.');
        }

        // DockRay rejects a whole report with a `..` segment in any path: one dropped file must not blind it.
        file_put_contents($this->root.'/storage/app/public/..\\evil.php', '<?php');
        mkdir($this->root.'/storage/app/public/..\\.hidden');

        $sent = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$sent) {
            $sent = json_decode($options['body'], true);

            return new MockResponse('{"success":true,"findings":{},"items":[],"next_report_in":21600}');
        });
        (new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', [], $http))->report(true);

        $paths = array_column($sent['suspicious'], 'path');
        $this->assertContains('storage/app/public/..?evil.php', $paths);
        $this->assertContains('storage/app/public/..?.hidden', $paths);

        foreach (array_merge($paths, array_column($sent['files'], 'path')) as $path) {
            $this->assertStringNotContainsString('\\', $path);
            $this->assertNotContains('..', explode('/', $path));
        }

        // A drive-letter-like name would get the whole report rejected too.
        $this->assertSame('C?evil.php', \Dock\Ray\Laravel\Security\SecurityScanner::path('C:evil.php'));
        $this->assertSame('..?x.php', \Dock\Ray\Laravel\Security\SecurityScanner::path('..\\x.php'));
    }

    public function test_a_scan_stopped_at_a_limit_is_truncated_and_lists_only_complete_groups(): void
    {
        $sent = null;
        $http = new MockHttpClient(function ($method, $url, $options) use (&$sent) {
            $sent = json_decode($options['body'], true);

            return new MockResponse('{"success":true,"findings":{},"items":[],"next_report_in":21600}');
        });

        $reporter = new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', [
            'release' => 'v1', 'max_files' => 3,
        ], $http);
        $result = $reporter->report(true);

        $this->assertSame('sent', $result['status']);
        $this->assertTrue($result['truncated']);
        $this->assertTrue($sent['scan']['truncated']);
        // Neither group fits in three files: a partial digest would look like a modification.
        $this->assertSame([], $sent['groups']);

        // A truncated scan continues within the hour, not after the regular interval.
        $state = json_decode((string) file_get_contents($this->root.'/state/state.json'), true);
        $this->assertLessThanOrEqual(time() + 3600, $state['next_at']);
    }

    public function test_hash_cache_keeps_digests_stable_and_notices_a_modified_file(): void
    {
        $scan = function (array $cache): array {
            $scanner = new SecurityScanner($this->root, $cache, microtime(true) + 10, 1000);

            return [$scanner->group('package', 'vendor', 'x', ['vendor'], 'vendor/', ['vendor/composer/', 'vendor/autoload.php']), $scanner->cache()];
        };

        [$first, $cache] = $scan([]);
        [$second] = $scan($cache);
        $this->assertSame($first, $second);
        $this->assertArrayNotHasKey('vendor/composer/autoload_classmap.php', $cache);

        // Same size, same mtime: only ctime (which PHP cannot set) gives the change away.
        $file = $this->root.'/vendor/laravel/framework/src/Foundation/Application.php';
        $mtime = filemtime($file);
        sleep(1);
        file_put_contents($file, '<?php class Applicatio2 {}');
        touch($file, $mtime);
        clearstatcache();
        [$third] = $scan($cache);
        $this->assertNotSame($first['digest'], $third['digest']);
    }

    public function test_upload_scan_is_incremental_but_keeps_earlier_findings(): void
    {
        $scanner = new SecurityScanner($this->root, [], microtime(true) + 10, 1000);
        $first = $scanner->uploads(['storage/app/public'], [], time(), 10.0, 1000);
        $paths = array_column($first['entries'], 'path');
        sort($paths);
        $this->assertSame([
            'storage/app/public/.cache', 'storage/app/public/2024/shell.php', 'storage/app/public/avatars/me.jpg', 'storage/app/public/media/.htaccess',
        ], $paths);
        $this->assertSame(['storage/app/public/avatars/me.jpg'], array_keys($first['state']['known']));

        // Later the same week: unchanged files are not read again, yet the
        // disguised script found before is carried over by its unchanged ctime.
        $later = time() + 1000;
        $state = ['since' => $later, 'full_at' => $later] + $first['state'];
        $scanner = new SecurityScanner($this->root, [], microtime(true) + 10, 1000);
        $second = $scanner->uploads(['storage/app/public'], $state, $later + 60, 10.0, 1000);
        $this->assertEqualsCanonicalizing($first['entries'], $second['entries']);
        $this->assertSame($later + 60, $second['state']['since']);
        $this->assertFalse($scanner->truncated());

        // A walk cut short by the entry limit is truncated and does not move "since".
        $scanner = new SecurityScanner($this->root, [], microtime(true) + 10, 1000);
        $cut = $scanner->uploads(['storage/app/public'], $state, $later + 120, 10.0, 2);
        $this->assertTrue($scanner->truncated());
        $this->assertSame($state, $cut['state']);
    }

    public function test_console_reports_take_runtime_posture_only_from_a_recent_web_request(): void
    {
        $cache = new Cache(new ArrayStore);
        $console = new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', ['console' => true], null, null, $cache);
        // Nothing measured on the web yet: the SAPI-dependent keys are left out.
        $this->assertSame(['debug_mode', 'debug_tools'], array_keys($console->posture()));

        $web = new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', ['console' => false], null, null, $cache);
        $web->captureWebPosture();
        $this->assertSame(['code_writable', 'display_errors', 'exec_available', 'debug_mode', 'debug_tools'], array_keys($console->posture()));
        $this->assertTrue($console->posture()['code_writable']);

        // Older than 30 days: not reported any more.
        $cache->put(SecurityReporter::WEB_POSTURE_KEY, ['at' => time() - 31 * 86400, 'values' => ['display_errors' => true]], 3600);
        $this->assertSame(['debug_mode', 'debug_tools'], array_keys($console->posture()));

        // The console never overwrites what a web request measured.
        $console->captureWebPosture();
        $this->assertSame(['display_errors' => true], $cache->get(SecurityReporter::WEB_POSTURE_KEY)['values']);
    }

    public function test_posture_reports_only_laravel_keys(): void
    {
        $reporter = new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', ['debug' => false, 'console' => false]);
        $posture = $reporter->posture();

        $this->assertSame(['code_writable', 'display_errors', 'exec_available', 'debug_mode', 'debug_tools'], array_keys($posture));
        $this->assertTrue($posture['code_writable']);
        $this->assertFalse($posture['debug_mode']);
        $this->assertSame(
            class_exists('Spatie\LaravelIgnition\IgnitionServiceProvider') || class_exists('Laravel\Telescope\TelescopeServiceProvider') || class_exists('Barryvdh\Debugbar\ServiceProvider'),
            $posture['debug_tools']
        );
    }

    public function test_the_artisan_command_prints_a_sanitized_summary(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode([
            'success' => true,
            'findings' => ['critical' => 1, 'high' => 0, 'medium' => 0, 'low' => 0],
            'items' => [['code' => 'php_in_uploads', 'severity' => 'critical', 'subject' => 'storage/app/public/2024/shell.php', 'title' => '<error>PHP in uploads</error>', 'url' => 'https://dockray.example/panel/projects/1/security']],
        ])));
        $app = new Application($this->root);

        try {
            $app->instance(SecurityReporter::class, new SecurityReporter($this->auth(), true, $this->root, $this->root.'/state', [], $http));
            $artisan = new Artisan($app, $app['events'], 'test');
            $artisan->add(new ReportSecurity);
            $output = new BufferedOutput;
            $this->assertSame(0, $artisan->run(new StringInput('ray:security --force'), $output));
            $text = $output->fetch();
            $this->assertStringContainsString('DockRay security: sent', $text);
            $this->assertStringContainsString('critical 1', $text);
            $this->assertStringContainsString('<error>PHP in uploads</error>', $text);
            $this->assertStringContainsString('Details: https://dockray.example/panel/projects/1/security', $text);
            $this->assertStringNotContainsString('secret', $text);
        } finally {
            $app->flush();
        }
    }

    private function auth(): AuthData
    {
        return AuthData::create('project', 'secret', 'https://dockray.example');
    }

    /** @param array<string, string> $hashes */
    private function digest(array $hashes): string
    {
        uksort($hashes, 'strcmp');
        $data = '';

        foreach ($hashes as $path => $hash) {
            $data .= $path."\0".$hash."\n";
        }

        return hash('sha256', $data);
    }
}
