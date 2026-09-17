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
RAY_URL=https://dockray.io
RAY_TRACES_SAMPLE_RATE=1
```

Without `RAY_TOKEN` and `RAY_PRIVATE_KEY` the package stays inert — no
requests, no errors. Everything else lives in `config/ray.php`.

## Reporting exceptions

Laravel 11–13 with the new bootstrap, in `bootstrap/app.php`:

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

The package includes ready-to-use middleware:
`Dock\Ray\Laravel\Http\Middleware\TrackTransaction`. No application wrapper
is required. Provider discovery alone does **not** enable transaction tracking.
Register the middleware once in each route group you want measured.

### Laravel 11–13 with `bootstrap/app.php`

```php
use Dock\Ray\Laravel\Http\Middleware\TrackTransaction;
use Illuminate\Foundation\Configuration\Middleware;

->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('web', TrackTransaction::class);
    // Optional, for ordinary application APIs:
    // $middleware->appendToGroup('api', TrackTransaction::class);
})
```

### Applications with `app/Http/Kernel.php`

This also applies to upgraded Laravel applications that retain the old kernel.
Add the middleware to the existing group, keeping its other entries:

```php
protected $middlewareGroups = [
    'web' => [
        // Existing middleware...
        \Dock\Ray\Laravel\Http\Middleware\TrackTransaction::class,
    ],
];
```

Use route groups, not the global middleware stack: the middleware needs the
matched route to name transactions (`GET /orders/{order}` instead of a separate
name for every order). It measures downstream handling and records the response
status. Exceptions escaping the middleware are not completed transactions;
configure exception reporting separately as described above.

### Configuration and verification

Set both project credentials and `RAY_TRACES_SAMPLE_RATE=1` for the initial test.
The rate defaults to `0` (disabled); `1` measures every eligible request and `0.1`
measures approximately 10%. Incomplete credentials disable tracking.
`RAY_URL` is the base server URL, without `/api/v1` or a project token.

After deployment or environment changes:

```shell
php artisan config:cache
```

Restart persistent application processes as appropriate for your deployment.
Open an HTTP page such as `/login`, then check the project's transactions in
DockRay. `report(new RuntimeException(...))` in Tinker tests **exceptions**, not
HTTP transactions. No queue worker is required for transaction delivery.

Published `config/ray.php` can exclude paths:

```php
'ignore_transactions' => ['horizon*', 'telescope*', '_debugbar*', 'health*'],
```

**When monitoring the DockRay server itself, never attach tracking to its ingest
routes.** Reporting a transaction about receiving a transaction creates a loop.
For the DockRay panel, attach this middleware only to `web`, leaving ingest outside
that group.

### Optional application circuit breaker

The middleware emits `Dock\Ray\Laravel\Events\TransactionStarting` before
starting a transaction. A listener can return `false` to skip tracking, for example
while the application's reporting circuit breaker is open. Return `null` to allow
other listeners to run. Register listeners in a service provider:

```php
use Dock\Ray\Laravel\Events\TransactionStarting;
use Illuminate\Support\Facades\Event;

Event::listen(TransactionStarting::class, function (TransactionStarting $event) {
    // DockRay panel-specific service; not required by other applications:
    return app(\App\Support\Monitoring\SelfReport::class)->suspended()
        ? false
        : null;
});
```

After installing the release containing this event, the DockRay panel can replace
`App\Http\Middleware\TrackPanelTransaction` in its `web` group with the package's
`TrackTransaction`, register the listener above, and remove the wrapper class.
Keep the existing exception hook and SDK logger: this event controls transactions
only. Publishing this package does not update consuming applications; update their
Composer lock file and deploy the updated dependencies too.

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

## Tests

```shell
composer install
composer test
```

For development inside a consuming application, reuse its installed dependencies:

```shell
DOCKRAY_TEST_AUTOLOAD=/absolute/path/to/app/vendor/autoload.php \
    php /absolute/path/to/app/vendor/bin/phpunit -c phpunit.xml
```

The tests load this package's source, including when the consumer uses an optimized
Composer classmap. Transport is replaced in tests; no requests leave the process.
The local verification used Laravel 13 and PHP 8.5; it is not a compatibility matrix
for all supported Laravel/PHP versions.

## License

MIT.
