# DockTHOR for Laravel

Reports Laravel exceptions and HTTP transactions to [DockTHOR](https://dock.codes).

## Installation

```shell
composer require dockcodes/dock-thor-laravel
php artisan vendor:publish --tag=thor-config
```

The service provider is discovered automatically.

## Configuration

```dotenv
THOR_TOKEN=project-token
THOR_PRIVATE_KEY=project-private-key
THOR_URL=https://thor.dock.codes
THOR_TRACES_SAMPLE_RATE=0.2
```

Without `THOR_TOKEN` and `THOR_PRIVATE_KEY` the package stays inert — no
requests, no errors. Everything else lives in `config/thor.php`.

## Reporting exceptions

Laravel 11 and 12, in `bootstrap/app.php`:

```php
use Dock\Thor\Laravel\Thor;

->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->reportable(fn (Throwable $e) => Thor::report($e));
})
```

Laravel 10, in `app/Exceptions/Handler.php`:

```php
public function register(): void
{
    $this->reportable(fn (Throwable $e) => Thor::report($e));
}
```

`Thor::report()` never throws and never rethrows: a monitoring outage must not
break the error handling it was called from. Exceptions listed in
`thor.ignore_exceptions` are skipped, on top of Laravel's own `dontReport`.

Reporting anything by hand works the same way:

```php
Thor::message('Nightly import finished late', \Dock\Thor\Severity::warning());
```

## Transactions

Add the middleware to the groups you want measured — usually `web` and `api`:

```php
->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware) {
    $middleware->appendToGroup('web', \Dock\Thor\Laravel\Http\Middleware\TrackTransaction::class);
})
```

Transactions are named after the route pattern (`GET /orders/{order}`), so one
route stays one row in the panel no matter how many identifiers pass through it.
Nothing is measured while `thor.traces_sample_rate` is `0`.

## User context

The middleware attaches the authenticated user's id, e-mail and name. Turn it
off with `THOR_SEND_DEFAULT_USER=false`, or replace it:

```php
Thor::setUser(['id' => $account->id, 'username' => $account->slug]);
```

The IP address and user agent are only attached when
`THOR_SEND_DEFAULT_PII=true`.

## Queues and console

Both run outside the middleware stack, so they report errors but produce no
transactions. The hub is a singleton for the whole process — a long-running
queue worker keeps one client and one connection.

## License

MIT.
