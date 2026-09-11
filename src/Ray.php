<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel;

use Dock\Ray\EventId;
use Dock\Ray\Severity;
use Dock\Ray\State\HubInterface;
use Dock\Ray\State\Scope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\App;

/**
 * Wejście dla aplikacji: zgłoszenie wyjątku i dostęp do huba.
 *
 * Zgłoszenie jest zawsze bezpieczne — awaria monitoringu nie może wywrócić
 * obsługi błędu, w której właśnie jesteśmy.
 */
final class Ray
{
    public static function hub(): HubInterface
    {
        return App::make(HubInterface::class);
    }

    public static function report(\Throwable $exception): ?EventId
    {
        if (self::shouldIgnore($exception)) {
            return null;
        }

        try {
            return self::hub()->captureException($exception);
        } catch (\Throwable $failure) {
            return null;
        }
    }

    public static function message(string $message, ?Severity $level = null): ?EventId
    {
        try {
            return self::hub()->captureMessage($message, $level);
        } catch (\Throwable $failure) {
            return null;
        }
    }

    public static function configureScope(callable $callback): void
    {
        try {
            self::hub()->configureScope($callback);
        } catch (\Throwable $failure) {
            // Kontekst jest dodatkiem do zdarzenia, nie powodem do awarii.
        }
    }

    public static function setUser(?array $user): void
    {
        self::configureScope(static function (Scope $scope) use ($user): void {
            $scope->setUser($user ?? ['id' => null]);
        });
    }

    private static function shouldIgnore(\Throwable $exception): bool
    {
        $ignored = (array) App::make(Repository::class)->get('ray.ignore_exceptions', []);

        foreach ($ignored as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
