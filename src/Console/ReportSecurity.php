<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Console;

use Dock\Ray\Laravel\Security\SecurityReporter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ReportSecurity extends Command
{
    protected $signature = 'ray:security {--force : Send even when the next report is not due}';

    protected $description = 'Report file integrity and security settings to DockRay (explicit opt-in required)';

    public function handle(SecurityReporter $reporter): int
    {
        $result = $reporter->report((bool) $this->option('force'));
        $this->line('DockRay security: '.$result['status'].(! empty($result['truncated']) ? ' (scan truncated, continues next hour)' : ''));

        if ($result['status'] === 'sent') {
            $this->summary($result);
        }

        return in_array($result['status'], ['sent', 'not_due', 'disabled', 'busy', 'backoff'], true) ? 0 : 1;
    }

    /**
     * Open findings as DockRay sees them. Everything here came from the
     * server: already stripped of control characters by the reporter, and
     * escaped so a `<tag>` in a title cannot style or hide console output.
     *
     * @param array<string, mixed> $result
     */
    private function summary(array $result): void
    {
        $counts = [];

        foreach ((array) ($result['findings'] ?? []) as $severity => $count) {
            $counts[] = $severity.' '.(int) $count;
        }

        $this->line('Open findings: '.OutputFormatter::escape(implode(', ', $counts)));

        foreach ((array) ($result['items'] ?? []) as $item) {
            $this->line(OutputFormatter::escape(sprintf(
                '  [%s] %s%s',
                $item['severity'] ?? 'unknown',
                $item['title'] ?? $item['code'] ?? '',
                isset($item['subject']) ? ': '.$item['subject'] : ''
            )));
        }

        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! empty($item['url'])) {
                $this->line('Details: '.OutputFormatter::escape((string) $item['url']));

                break;
            }
        }
    }
}
