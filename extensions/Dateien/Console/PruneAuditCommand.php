<?php

namespace Extensions\Dateien\Console;

use Extensions\Dateien\Services\Maintenance;
use Illuminate\Console\Command;

class PruneAuditCommand extends Command
{
    protected $signature = 'dateien:prune-audit';

    protected $description = 'Deletes Dateien audit events older than the retention setting';

    public function handle(Maintenance $maintenance): int
    {
        $deleted = $maintenance->pruneAudit();
        if ($deleted > 0) {
            $this->line("{$deleted} Protokolleinträge gelöscht.");
        }

        return self::SUCCESS;
    }
}
