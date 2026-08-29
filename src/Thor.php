<?php

declare(strict_types=1);

namespace Dock\Thor\Laravel;

use Dock\Thor\EventId;
use Dock\Thor\Severity;
use Dock\Thor\State\HubInterface;
use Dock\Thor\State\Scope;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\App;

/**
 * Wejście dla aplikacji: zgłoszenie wyjątku i dostęp do huba.
 *
 * Zgłoszenie jest zawsze bezpieczne — awaria monitoringu nie może wywrócić
 * obsługi błędu, w której właśnie jesteśmy.
 */
final class Thor
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
        } catch (\Throwable) {
            return null;
        }
    }

    public static function message(string $message, ?Severity $level = null): ?EventId
    {
        try {
            return self::hub()->captureMessage($message, $level);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function configureScope(callable $callback): void
    {
        try {
            self::hub()->configureScope($callback);
        } catch (\Throwable) {
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
        $ignored = (array) App::make(Repository::class)->get('thor.ignore_exceptions', []);

        foreach ($ignored as $class) {
            if ($exception instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
