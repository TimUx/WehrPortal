<?php

namespace Modules\Lehrgaenge\src\Console\Commands;

use App\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Modules\Lehrgaenge\src\Services\RegistrationService;

class RemindRefresh extends Command
{
    protected $signature = 'lehrgaenge:remind-refresh';

    protected $description = 'Remind Kameraden and Ausbildungsleiter of refreshers that become due';

    public function handle(RegistrationService $registrations): int
    {
        if (! app(ModuleRegistry::class)->isActive('lehrgaenge')) {
            return self::SUCCESS;
        }
        $this->info($registrations->remindRefresh().' Auffrischungen gemeldet.');

        return self::SUCCESS;
    }
}
