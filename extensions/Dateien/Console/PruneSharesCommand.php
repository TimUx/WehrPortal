<?php

namespace Extensions\Dateien\Console;

use Extensions\Dateien\Services\ShareService;
use Illuminate\Console\Command;

class PruneSharesCommand extends Command
{
    protected $signature = 'dateien:prune-shares';

    protected $description = 'Removes public links 30 days after they expired or were revoked';

    public function handle(ShareService $shares): int
    {
        $count = $shares->prune();
        if ($count > 0) {
            $this->line("{$count} öffentliche Links entfernt.");
        }

        return self::SUCCESS;
    }
}
