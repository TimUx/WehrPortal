<?php

namespace Modules\Personalverwaltung\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Personalverwaltung\src\Services\PersonnelService;

class SyncHistory extends Command
{
    protected $signature = 'personalverwaltung:sync-history';

    protected $description = 'Write changes of Dienstgrad, Abteilung, Funktion and status into the career of every Kamerad';

    public function handle(PersonnelService $personnel): int
    {
        $this->info('Laufbahn-Änderungen: '.$personnel->syncAll());

        return self::SUCCESS;
    }
}
