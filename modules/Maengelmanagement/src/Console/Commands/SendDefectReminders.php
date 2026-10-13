<?php

namespace Modules\Maengelmanagement\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Maengelmanagement\src\Services\DefectService;

class SendDefectReminders extends Command
{
    protected $signature = 'maengel:remind';

    protected $description = 'Erinnert die Verantwortlichen an liegengebliebene Mängel';

    public function handle(DefectService $service): int
    {
        $this->info($service->remind().' Mängel in der Erinnerung.');

        return self::SUCCESS;
    }
}
