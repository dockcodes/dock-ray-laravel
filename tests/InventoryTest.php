<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Tests;

use Dock\Ray\Inventory\InventoryReporter;
use Dock\Ray\Laravel\Console\ReportInventory;
use Dock\Ray\Laravel\RayOptions;
use Dock\Ray\Laravel\RayServiceProvider;
use Dock\Ray\Options;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InventoryTest extends TestCase
{
    public function test_options_are_opt_in_and_use_the_configured_application_url(): void
    {
        $config = new Repository(['app' => ['url' => 'https://stage.example.com']]);
        $this->assertFalse(RayOptions::fromConfig($config, '/project')['inventory_enabled']);
        $config->set('ray.inventory', ['enabled' => true, 'exclude_packages' => ['company/*']]);
        $options = RayOptions::fromConfig($config, '/project');
        $this->assertTrue($options['inventory_enabled']);
        $this->assertSame('https://stage.example.com', $options['inventory_site_url']);
        $this->assertSame(['company/*'], $options['inventory_exclude_packages']);
        $config->set('ray.inventory.site_url', 'https://override.example.com');
        $this->assertSame('https://override.example.com', RayOptions::fromConfig($config, '/project')['inventory_site_url']);
    }

    public function test_the_provider_schedules_only_enabled_inventory_without_http_during_boot(): void
    {
        foreach ([false, true] as $enabled) {
            $app = new Application(sys_get_temp_dir());
            $app->instance('config', new Repository([
                'app' => ['url' => 'https://app.example.com'],
                'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
                'ray' => ['token' => null, 'inventory' => ['enabled' => $enabled]],
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
                $this->assertStringContainsString('ray:inventory', $events[0]->command);
                $this->assertSame('0 * * * *', $events[0]->expression);
                $this->assertTrue($events[0]->withoutOverlapping);
            }
            $app->flush();
        }
    }

    public function test_manual_artisan_report_sends_laravel_inventory_through_the_shared_safe_reporter(): void
    {
        $root = sys_get_temp_dir().'/dockray-laravel-inventory-test-'.bin2hex(random_bytes(8));
        mkdir($root.'/vendor/composer', 0700, true);
        file_put_contents($root.'/composer.json', '{"name":"company/app"}');
        file_put_contents($root.'/composer.lock', '{"packages":[{"name":"laravel/framework"}]}');
        file_put_contents($root.'/vendor/composer/installed.json', '{"packages":[{"name":"laravel/framework","version":"v12.1.0"}]}');
        $http = new MockHttpClient(function ($method, $url, $options) {
            $payload = json_decode($options['body'], true);
            $this->assertSame('laravel', $payload['platform']);
            $this->assertSame('12.1.0', $payload['platform_version']);
            $this->assertSame('laravel/framework', $payload['components'][0]['slug']);

            return new MockResponse('{"success":true}');
        });
        $app = new Application($root);
        try {
            $app->instance(InventoryReporter::class, new InventoryReporter(new Options([
                'token' => 'project', 'private_key' => 'secret',
                'inventory_enabled' => true, 'inventory_site_url' => 'https://app.example.com',
            ]), $root, $root.'/state', 'laravel', $http));
            $artisan = new Artisan($app, $app['events'], 'test');
            $artisan->add(new ReportInventory);
            $output = new BufferedOutput;
            $this->assertSame(0, $artisan->run(new StringInput('ray:inventory --force'), $output));
            $this->assertStringContainsString('sent', $output->fetch());
        } finally {
            $app->flush();
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($root);
        }
    }
}
