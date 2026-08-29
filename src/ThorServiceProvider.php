<?php

declare(strict_types=1);

namespace Dock\Thor\Laravel;

use Dock\Thor\ClientBuilder;
use Dock\Thor\State\Hub;
use Dock\Thor\State\HubInterface;
use Dock\Thor\ThorSdk;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class ThorServiceProvider extends ServiceProvider
{
    public const VERSION = '1.0.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/thor.php', 'thor');

        $this->app->singleton(HubInterface::class, function (Application $app): HubInterface {
            $builder = ClientBuilder::create(
                ThorOptions::fromConfig($app->make(Repository::class), $app->basePath())
            );

            $builder->setSdkIdentifier('thor.php.laravel');
            $builder->setSdkVersion(self::VERSION);

            $hub = new Hub($builder->getClient());

            ThorSdk::setCurrentHub($hub);

            return $hub;
        });

        $this->app->alias(HubInterface::class, 'thor');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/thor.php' => $this->app->configPath('thor.php')], 'thor-config');

        /*
         | Hub powstaje na starcie, a nie dopiero przy pierwszym błędzie:
         | rejestruje globalne procesory zdarzeń, więc zbudowany w handlerze
         | zgubiłby kontekst żądania.
         */
        if ($this->app->make(Repository::class)->get('thor.token')) {
            $this->app->make(HubInterface::class);
        }
    }
}
