<?php

namespace Modules\Fuehrerscheine\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Fuehrerscheine\src\Services\LicenseService;

class SendLicenseReminders extends Command
{
    protected $signature = 'fuehrerscheine:remind';

    protected $description = 'Remind Kameraden of Führerschein classes running out and due checks';

    public function handle(LicenseService $licenses): int
    {
        $this->info($licenses->remind().' Kameraden erinnert.');

        return self::SUCCESS;
    }
}
