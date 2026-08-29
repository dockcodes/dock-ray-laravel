<?php

declare(strict_types=1);

namespace Dock\Thor\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * Przepisuje `config/thor.php` na tablicę opcji SDK.
 *
 * Wydzielone z dostawcy usług, bo tę samą konfigurację czyta polecenie
 * konsolowe i testy — a dostawca nie jest miejscem na logikę.
 */
final class ThorOptions
{
    public static function fromConfig(Repository $config, string $basePath): array
    {
        $options = array_merge((array) $config->get('thor.options', []), [
            'token' => $config->get('thor.token'),
            'private_key' => $config->get('thor.private_key'),
            'url' => $config->get('thor.url'),
            'environment' => $config->get('thor.environment'),
            'release' => $config->get('thor.release'),
            'sample_rate' => (float) $config->get('thor.sample_rate', 1),
            'traces_sample_rate' => (float) $config->get('thor.traces_sample_rate', 0),
            'send_default_pii' => (bool) $config->get('thor.send_default_pii', false),
            'prefixes' => [$basePath],
            'in_app_exclude' => [$basePath . '/vendor'],
        ]);

        return array_filter($options, static fn ($value): bool => $value !== null);
    }
}
