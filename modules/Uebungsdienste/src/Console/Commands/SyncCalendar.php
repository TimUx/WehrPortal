<?php

namespace Modules\Uebungsdienste\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Uebungsdienste\src\Services\CalendarLink;

class SyncCalendar extends Command
{
    protected $signature = 'uebungsdienste:sync-calendar';

    protected $description = 'Put the Übungsdienste that are not in the Kalender yet into it (without the topic)';

    public function handle(CalendarLink $link): int
    {
        $this->info('Übungsdienste in den Kalender übernommen: '.$link->syncMissing());

        return self::SUCCESS;
    }
}
