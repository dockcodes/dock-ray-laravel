<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel;

use Dock\Ray\ClientBuilder;
use Dock\Ray\Inventory\InventoryReporter;
use Dock\Ray\Options;
use Dock\Ray\Laravel\Console\ReportInventory;
use Dock\Ray\Laravel\Console\ReportSecurity;
use Dock\Ray\Laravel\Security\SecurityReporter;
use Illuminate\Console\Scheduling\Schedule;
use Dock\Ray\State\Hub;
use Dock\Ray\State\HubInterface;
use Dock\Ray\RaySdk;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class RayServiceProvider extends ServiceProvider
{
    public const VERSION = '1.4.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/ray.php', 'ray');

        $this->app->singleton(HubInterface::class, function (Application $app): HubInterface {
            $builder = ClientBuilder::create(
                RayOptions::fromConfig($app->make(Repository::class), $app->basePath())
            );

            $builder->setSdkIdentifier('ray.php.laravel');
            $builder->setSdkVersion(self::VERSION);

            $hub = new Hub($builder->getClient());

            RaySdk::setCurrentHub($hub);

            return $hub;
        });

        $this->app->singleton(InventoryReporter::class, function (Application $app): InventoryReporter {
            return new InventoryReporter(
                new Options(RayOptions::fromConfig($app->make(Repository::class), $app->basePath())),
                $app->basePath(), $app->storagePath() . '/app/dockray/inventory', 'laravel'
            );
        });

        $this->app->singleton(SecurityReporter::class, function (Application $app): SecurityReporter {
            $config = $app->make(Repository::class);

            return new SecurityReporter(
                (new Options(RayOptions::fromConfig($config, $app->basePath())))->getAuthData(),
                (bool) $config->get('ray.security.enabled', false),
                $app->basePath(), $app->storagePath() . '/app/dockray/security',
                [
                    'site_url' => $config->get('ray.security.site_url') ?: ($config->get('ray.inventory.site_url') ?: $config->get('app.url')),
                    'release' => $config->get('ray.release'),
                    'debug' => (bool) $config->get('app.debug', false),
                    'platform_version' => $app->version(),
                    'agent_version' => self::VERSION,
                ],
                null,
                null,
                self::cacheStore($app)
            );
        });

        $this->app->alias(HubInterface::class, 'ray');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReportInventory::class, ReportSecurity::class]);
            $this->app->booted(function (): void {
                if ($this->app->make(Repository::class)->get('ray.inventory.enabled', false)) {
                    // Hourly tick; SDK sends every 12 hours or after package changes.
                    $this->app->make(Schedule::class)->command('ray:inventory')->hourly()->withoutOverlapping(10);
                }

                if ($this->app->make(Repository::class)->get('ray.security.enabled', false)) {
                    // Cheap tick: the command sends every 6 hours (or what the server asks
                    // for) and a minute after a deploy. In the background, because a scan
                    // may take 20 s and must not hold up the application's own tasks.
                    $this->app->make(Schedule::class)->command('ray:security')->everyFiveMinutes()->withoutOverlapping(30)->runInBackground();
                }
            });
        }

        if (! $this->app->runningInConsole() && $this->app->make(Repository::class)->get('ray.security.enabled', false)) {
            // The scheduler's console process cannot tell what visitors get from
            // PHP; the report takes these settings from a recent page view.
            $this->app->terminating(function (): void {
                try {
                    $request = $this->app->bound('request') ? $this->app->make('request') : null;

                    if ($request instanceof Request && ($request->ajax() || $request->wantsJson() || $request->is('api', 'api/*'))) {
                        return;
                    }

                    $this->app->make(SecurityReporter::class)->captureWebPosture();
                } catch (\Throwable $exception) {
                    // Monitoring must not add an error to a response that went fine.
                }
            });
        }

        $this->publishes([__DIR__ . '/../config/ray.php' => $this->app->configPath('ray.php')], 'ray-config');

        /*
         | Hub powstaje na starcie, a nie dopiero przy pierwszym błędzie:
         | rejestruje globalne procesory zdarzeń, więc zbudowany w handlerze
         | zgubiłby kontekst żądania.
         */
        if ($this->app->make(Repository::class)->get('ray.token')) {
            $this->app->make(HubInterface::class);
        }
    }

    /** The default cache store, shared by web requests and the scheduler; null when there is none. */
    private static function cacheStore(Application $app): ?CacheRepository
    {
        try {
            return $app->bound('cache') ? $app->make('cache')->store() : null;
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
