<?php

namespace Modules\Kalender\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\PollService;

class RemindPendingResponses extends Command
{
    protected $signature = 'kalender:remind-responses';

    protected $description = 'Remind Kameraden who have not answered an event or a Terminabfrage yet';

    public function handle(EventService $events, PollService $polls): int
    {
        $this->info($events->remindPending().' reminder(s) sent, '.$polls->remind().' for Terminabfragen');

        return self::SUCCESS;
    }
}
