<?php

declare(strict_types=1);

namespace Dock\Thor\Laravel\Http\Middleware;

use Closure;
use Dock\Thor\Framework\HttpTransaction;
use Dock\Thor\Laravel\Thor;
use Dock\Thor\State\Scope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackTransaction
{
    public function __construct(private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldTrack($request)) {
            return $next($request);
        }

        $transaction = HttpTransaction::start(
            $this->name($request),
            $request->fullUrl(),
            $request->method(),
            $this->startedAt(),
            Thor::hub(),
        );

        $transaction->measureHandling('laravel.handle');

        $this->rememberUser($request);

        $response = $next($request);

        $transaction->finish($response->getStatusCode());

        return $response;
    }

    /**
     * Nazwą transakcji jest wzorzec trasy, a nie adres — inaczej każdy
     * identyfikator w ścieżce dawałby osobną transakcję i lista w panelu
     * rozsypałaby się na tysiące pozycji.
     */
    private function name(Request $request): string
    {
        $route = $request->route();
        $uri = $route !== null && method_exists($route, 'uri') ? '/' . ltrim($route->uri(), '/') : $request->path();

        return $request->method() . ' ' . $uri;
    }

    private function shouldTrack(Request $request): bool
    {
        if ((float) $this->config->get('thor.traces_sample_rate', 0) <= 0) {
            return false;
        }

        $ignored = (array) $this->config->get('thor.ignore_transactions', []);

        return $ignored === [] || ! $request->is(...$ignored);
    }

    private function rememberUser(Request $request): void
    {
        if (! $this->config->get('thor.send_default_user', true)) {
            return;
        }

        $user = $request->user();

        if ($user === null) {
            return;
        }

        Thor::configureScope(static function (Scope $scope) use ($user): void {
            $scope->setUser(array_filter([
                'id' => method_exists($user, 'getAuthIdentifier') ? $user->getAuthIdentifier() : null,
                'email' => $user->email ?? null,
                'username' => $user->name ?? null,
            ], static fn ($value): bool => $value !== null));
        });
    }

    private function startedAt(): float
    {
        return (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }
}
