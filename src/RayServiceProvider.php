<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel;

use Dock\Ray\ClientBuilder;
use Dock\Ray\Inventory\InventoryReporter;
use Dock\Ray\Options;
use Dock\Ray\Laravel\Console\ReportInventory;
use Illuminate\Console\Scheduling\Schedule;
use Dock\Ray\State\Hub;
use Dock\Ray\State\HubInterface;
use Dock\Ray\RaySdk;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class RayServiceProvider extends ServiceProvider
{
    public const VERSION = '1.3.0';

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

        $this->app->alias(HubInterface::class, 'ray');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ReportInventory::class]);
            $this->app->booted(function (): void {
                if ($this->app->make(Repository::class)->get('ray.inventory.enabled', false)) {
                    // Hourly tick; SDK sends every 12 hours or after package changes.
                    $this->app->make(Schedule::class)->command('ray:inventory')->hourly()->withoutOverlapping(10);
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
}
