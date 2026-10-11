<?php

namespace Modules\Kalender\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Kalender\src\Services\SourceSync;

class SyncCalendarSources extends Command
{
    protected $signature = 'kalender:sync-sources';

    protected $description = 'Pull the external iCal calendars in';

    public function handle(SourceSync $sync): int
    {
        foreach ($sync->syncAll() as $name => $count) {
            $this->line($name.': '.($count < 0 ? 'Fehler' : $count.' Termine'));
        }

        return self::SUCCESS;
    }
}
