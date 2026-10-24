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
RAY_TRACES_SAMPLE_RATE=1
```

Without `RAY_TOKEN` and `RAY_PRIVATE_KEY` the package stays inert - no
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

Laravel 6 and 7, in the same file - they have no `register()` hook:

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
transactions. The hub is a singleton for the whole process - a long-running
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

## Opt-in vulnerability monitoring (bridge 1.3+, PHP SDK 1.4+)

Enable package inventory with one option, alongside your existing project credentials:

```dotenv
RAY_INVENTORY=true
```

The bridge uses the configured `APP_URL` (`config('app.url')`) as the site URL.
It must match the production or development URL configured for the DockRay project.
Override it with `RAY_INVENTORY_SITE_URL` when needed. If configuration is cached,
rebuild it with `php artisan config:cache` after changing these settings.
Existing published `config/ray.php` files can add the following optional block;
the package supplies defaults for installations without the block:

```php
'inventory' => [
    'enabled' => (bool) env('RAY_INVENTORY', false),
    'site_url' => env('RAY_INVENTORY_SITE_URL'),
    'exclude_packages' => ['company/*'],
],
```

Keep Laravel's normal scheduler running (`php artisan schedule:run` every minute).
The provider adds an hourly inventory check automatically; no requests are made
while serving HTTP pages or booting the provider. A changed snapshot is sent on
the next check; an unchanged snapshot is sent every 12 hours. To report immediately
on deployment, or to check configuration:

```shell
php artisan ray:inventory --force
```

`--force` still respects retry backoff. Disable reporting with `RAY_INVENTORY=false`.
The command prints only a sanitized status, never keys or the package payload.

The report includes PHP/agent/framework versions, the configured site URL, and
only names and versions of installed production Composer packages. It requires
`composer.lock` and installed Composer metadata, excludes dev dependencies and
the root application, and supports exclusion patterns for private namespaces.
It does not include code, `.env`, configuration, repository URLs, paths or users.
It does not scan PHP extensions or the operating system. Excluded packages cannot
be checked for vulnerabilities.

Delivery uses verified HTTPS, Bearer authentication, no redirects, short timeouts,
size limits, retry backoff and a lock. Local timing/hash state is stored privately
under `storage/app/dockray/inventory`; keep that directory persistent and outside
the public web root. For multi-server deployments, run the scheduler on one
server or use a shared filesystem for this lock.

DockRay maps Composer packages to its vulnerability database and closes findings
after a fixed package version is reported. Confirmed findings use the project's
existing notification recipients and channels, duplicate suppression and alert
scheduler (currently every 15 minutes). Reporting is opt-in in every environment;
use the appropriate project URL and credentials for each deployment.

## Opt-in security report (bridge 1.4+)

Off by default. Enable it alongside your existing project credentials:

```dotenv
RAY_SECURITY=true
```

Existing published `config/ray.php` files can add the optional block below; the
package supplies the defaults when it is missing:

```php
'security' => [
    'enabled' => (bool) env('RAY_SECURITY', false),
    'site_url' => env('RAY_SECURITY_SITE_URL'), // defaults to the inventory site URL, then APP_URL
],
```

Keep Laravel's scheduler running. The provider adds `ray:security` every five
minutes, in the background and without overlapping; the command itself decides
whether a report is due: every 6 hours (or the interval DockRay answers with,
clamped to 1–24 hours), on the first tick at least a minute after a deploy changed
`composer.lock` or `RAY_RELEASE`, and an hour after a scan that stopped at its limit. Nothing
is scanned or sent while serving HTTP pages or booting the provider. To report
now, or to see the open findings:

```shell
php artisan ray:security --force
```

`--force` still respects retry backoff. The command prints a sanitized status, the
number of open findings per severity and the findings DockRay returned; everything
from the server is stripped of control characters and escaped before printing.

What the report contains (`POST /api/v1/{token}/security`):

| Section | Content |
|---|---|
| `groups` | `package/vendor`: sha256 digest of the PHP/JS files in `vendor/`, versioned by the sha256 of `composer.lock` (`vendor/composer/` and `vendor/autoload.php` are left out — every `composer dump-autoload` rewrites them). `app/app`: the same over `app/`, `bootstrap/` (without `bootstrap/cache/`), `config/`, `routes/` and `public/`, only when `RAY_RELEASE` is set |
| `files` | sha256 and size of `public/index.php`, `artisan`, `.env`, `bootstrap/app.php`, `public/.htaccess`, `public/.user.ini` |
| `suspicious` | PHP files in `storage/app/public` and `public/storage`, files there that start with `<?php` under another extension, `.htaccess`/`.user.ini` there that make the server run PHP (`php_handler`), hidden directories there, PHP files in `public/` other than `index.php`, and backdoor indicator *names* found in them |
| `posture` | `code_writable`, `display_errors`, `exec_available` (see below), `debug_mode` (`app.debug`), `debug_tools` (Ignition, Telescope or Debugbar installed) |

It never contains file contents, absolute paths, users or configuration values —
hashing `.env` tells DockRay when the file changed and nothing else. DockRay records
the first report as the baseline; afterwards a changed digest without a version
change, a changed sensitive file or a suspicious file becomes a finding.

`code_writable`, `display_errors` and `exec_available` depend on the PHP SAPI, its
`php.ini` and the process user, so the scheduler's console process cannot measure
them. With the report enabled, the provider measures them after a page view (not
AJAX, JSON or `api/*` requests), at most once an hour, and keeps them in the default
cache store; the report uses them while they are younger than 30 days and otherwise
leaves the three keys out, so DockRay keeps its earlier verdict. Use a persistent
cache store shared by the web server and the scheduler (`file`, `redis`,
`database`…); with the `array` store the keys are never sent. `display_errors` is
the value PHP started the request with — Laravel switches the setting off once it
boots, so that value decides whether a bootstrap error reaches visitors.

Hashing is expensive, so per-file hashes are cached by size, mtime and ctime. The
upload directories are walked on every run, but file contents are read only for files
whose ctime is at or after the start of the previous run, plus a weekly full pass
that gets ten seconds per run and continues from a cursor; earlier findings are
carried over for files whose ctime has not changed, so every report is complete.
A walk that reaches 300,000 entries, or a scan that reaches 20 seconds or 200,000
files, is marked truncated: only groups hashed completely are sent, DockRay keeps
the findings it could not re-check, and the next run continues from the cache. State and cache live privately under
`storage/app/dockray/security`; keep that directory persistent and outside the web
root, and run the scheduler on one server (or share that directory) in multi-server
deployments.

Delivery uses verified HTTPS only, Bearer authentication, no redirects, a 5 s connect
and 20 s total timeout, a 2 MB body limit, retry backoff and a lock.

## Early fatal errors

The bridge reports errors once Laravel has booted. A fatal error before that — a
parse error in `bootstrap/app.php`, a broken file in `vendor/`, memory exhausted while
providers register — never reaches it. For those, the package ships a dependency-free
file (PHP 7.4+) to load with `auto_prepend_file`, in `php.ini`, `public/.user.ini` or
the PHP-FPM pool:

```ini
auto_prepend_file=/absolute/path/to/app/vendor/dockcodes/dock-ray-laravel/resources/early-fatal.php
```

It only registers a shutdown function. On a fatal error (`E_ERROR`, `E_PARSE`,
`E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`, `E_RECOVERABLE_ERROR`) it waits
until every other shutdown function ran — Laravel's error page, the SDK's own handler
— and sends one event to the regular ingest endpoint with `sdk.name`
`dockray.laravel.early`, unless a DockRay SDK client with its error listeners is
already active (then the bridge reported the error itself). It sends at most one
report per distinct error every ten seconds, never prints anything and never throws.

Credentials come from `RAY_TOKEN`, `RAY_PRIVATE_KEY`, `RAY_URL` (default
`https://dockray.io`), `RAY_ENVIRONMENT` (falls back to `APP_ENV`) and `RAY_RELEASE`.
Values Laravel loaded from `.env` are used when the error happened after that, but
with a cached configuration Laravel never reads `.env` — set the variables in the
server environment (PHP-FPM `env[RAY_TOKEN]`, the container environment) to cover the
earliest errors. Without credentials the file does nothing. It sends over HTTPS only
(plain HTTP only to `localhost`), with a 2 s connect and 3 s total timeout, after the
response was flushed to the visitor.
