<?php

declare(strict_types=1);

namespace Dock\Ray\Laravel\Console;

use Dock\Ray\Inventory\InventoryReporter;
use Illuminate\Console\Command;

final class ReportInventory extends Command
{
    protected $signature = 'ray:inventory {--force : Send even when an unchanged inventory is not due}';

    protected $description = 'Report installed production Composer packages to DockRay (explicit opt-in required)';

    public function handle(InventoryReporter $reporter): int
    {
        $result = $reporter->report((bool) $this->option('force'));
        $this->line('DockRay inventory: '.$result['status']);

        return in_array($result['status'], ['sent', 'not_due', 'disabled', 'busy', 'backoff'], true) ? 0 : 1;
    }
}
