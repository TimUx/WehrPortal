<?php

namespace Extensions\Dateien\Console;

use Extensions\Dateien\Services\FileService;
use Illuminate\Console\Command;

class DrainDeletionsCommand extends Command
{
    protected $signature = 'dateien:drain-deletions';

    protected $description = 'Retries deleting files of purged versions (with backoff)';

    public function handle(FileService $files): int
    {
        $result = $files->drainPendingDeletions();
        if ($result['deleted'] + $result['failed'] > 0) {
            $this->line("Gelöscht: {$result['deleted']}, fehlgeschlagen: {$result['failed']}, offen: {$result['kept']}");
        }

        return self::SUCCESS;
    }
}
