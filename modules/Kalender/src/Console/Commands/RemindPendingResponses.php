<?php

namespace Modules\Kalender\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Kalender\src\Services\EventService;

class RemindPendingResponses extends Command
{
    protected $signature = 'kalender:remind-responses';

    protected $description = 'Remind Kameraden who have not answered an event yet';

    public function handle(EventService $events): int
    {
        $this->info($events->remindPending().' reminder(s) sent');

        return self::SUCCESS;
    }
}
