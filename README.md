# DockRay for Laravel

Reports Laravel exceptions and HTTP transactions to [DockRay](https://dockray.io).

## Installation

```shell
composer require dockcodes/dock-ray-laravel
php artisan vendor:publish --tag=ray-config
```

The service provider is discovered automatically.

Requires PHP 7.4 or newer and Laravel 6 through 13.

## Configuration

```dotenv
RAY_TOKEN=project-token
RAY_PRIVATE_KEY=project-private-key
RAY_TRACES_SAMPLE_RATE=0.2
```

Without `RAY_TOKEN` and `RAY_PRIVATE_KEY` the package stays inert — no
requests, no errors. Everything else lives in `config/ray.php`.

## Reporting exceptions

Laravel 11, 12 and 13, in `bootstrap/app.php`:

```php
use Dock\Ray\Laravel\Ray;

->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
    $exceptions->reportable(fn (Throwable $e) => Ray::report($e));
})
```

Laravel 8, 9 and 10, in `app/Exceptions/Handler.php`:

```php
public function register(): void
{
    $this->reportable(fn (Throwable $e) => Ray::report($e));
}
```

Laravel 6 and 7, in the same file — they have no `register()` hook:

```php
public function report(Throwable $exception)
{
    Ray::report($exception);

    parent::report($exception);
}
```

`Ray::report()` never throws and never rethrows: a monitoring outage must not
break the error handling it was called from. Exceptions listed in
`ray.ignore_exceptions` are skipped, on top of Laravel's own `dontReport`.

Reporting anything by hand works the same way:

```php
Ray::message('Nightly import finished late', \Dock\Ray\Severity::warning());
```

## Transactions

Add the middleware to the groups you want measured — usually `web` and `api`:

```php
->withMiddleware(function (Illuminate\Foundation\Configuration\Middleware $middleware) {
    $middleware->appendToGroup('web', \Dock\Ray\Laravel\Http\Middleware\TrackTransaction::class);
})
```

Transactions are named after the route pattern (`GET /orders/{order}`), so one
route stays one row in the panel no matter how many identifiers pass through it.
Nothing is measured while `ray.traces_sample_rate` is `0`.

## User context

The middleware attaches the authenticated user's id, e-mail and name. Turn it
off with `RAY_SEND_DEFAULT_USER=false`, or replace it:

```php
Ray::setUser(['id' => $account->id, 'username' => $account->slug]);
```

The IP address and user agent are only attached when
`RAY_SEND_DEFAULT_PII=true`.

## Queues and console

Both run outside the middleware stack, so they report errors but produce no
transactions. The hub is a singleton for the whole process — a long-running
queue worker keeps one client and one connection.

## License

MIT.
