<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * Przepisuje `config/ray.php` na tablicę opcji SDK.
 *
 * Wydzielone z dostawcy usług, bo tę samą konfigurację czyta polecenie
 * konsolowe i testy - a dostawca nie jest miejscem na logikę.
 */
final class RayOptions
{
    public static function fromConfig(Repository $config, string $basePath): array
    {
        $options = array_merge((array) $config->get('ray.options', []), [
            'token' => $config->get('ray.token'),
            'private_key' => $config->get('ray.private_key'),
            'url' => $config->get('ray.url'),
            'environment' => $config->get('ray.environment'),
            'release' => $config->get('ray.release'),
            'sample_rate' => (float) $config->get('ray.sample_rate', 1),
            'traces_sample_rate' => (float) $config->get('ray.traces_sample_rate', 0),
            'send_default_pii' => (bool) $config->get('ray.send_default_pii', false),
            'inventory_enabled' => (bool) $config->get('ray.inventory.enabled', false),
            'inventory_site_url' => $config->get('ray.inventory.site_url') ?: $config->get('app.url'),
            'inventory_exclude_packages' => (array) $config->get('ray.inventory.exclude_packages', []),
            'prefixes' => [$basePath],
            'in_app_exclude' => [$basePath . '/vendor'],
        ]);

        return array_filter($options, static fn ($value): bool => $value !== null);
    }
}
