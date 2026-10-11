<?php

namespace Modules\Fahrzeuge\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Fahrzeuge\src\Services\FleetService;

class SendFleetReminders extends Command
{
    protected $signature = 'fahrzeuge:remind';

    protected $description = 'Mail the Fahrzeugwarte which vehicle deadlines (HU, SP, UVV …) are newly due or overdue';

    public function handle(FleetService $fleet): int
    {
        $this->info('Gemeldet: '.$fleet->remind());

        return self::SUCCESS;
    }
}
