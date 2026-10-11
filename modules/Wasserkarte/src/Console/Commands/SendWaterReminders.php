<?php

namespace Modules\Wasserkarte\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Wasserkarte\src\Services\WaterService;

class SendWaterReminders extends Command
{
    protected $signature = 'wasserkarte:remind';

    protected $description = 'Mail the Hydrantenwarte which Löschwasserquellen are due for inspection';

    public function handle(WaterService $water): int
    {
        $this->info('Gemeldet: '.$water->remind());

        return self::SUCCESS;
    }
}
