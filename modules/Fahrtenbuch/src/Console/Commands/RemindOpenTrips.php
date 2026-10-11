<?php

namespace Modules\Fahrtenbuch\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Fahrtenbuch\src\Services\LogbookService;

class RemindOpenTrips extends Command
{
    protected $signature = 'fahrtenbuch:remind';

    protected $description = 'Remind drivers whose Fahrtenbuch trip has been open for hours to end it';

    public function handle(LogbookService $logbook): int
    {
        $this->info('Erinnert: '.$logbook->remind());

        return self::SUCCESS;
    }
}
