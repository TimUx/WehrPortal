<?php

namespace Extensions\Dateien\Console;

use Extensions\Dateien\Office\OfficeService;
use Illuminate\Console\Command;

class PruneOfficeSessionsCommand extends Command
{
    protected $signature = 'dateien:prune-office-sessions';

    protected $description = 'Removes expired and revoked Online-Office sessions';

    public function handle(OfficeService $office): int
    {
        $count = $office->prune();
        if ($count > 0) {
            $this->line("{$count} Office-Sitzungen entfernt.");
        }

        return self::SUCCESS;
    }
}
