<?php

namespace Modules\Einsatzbericht\src\Console\Commands;

use App\Services\ModuleRegistry;
use Illuminate\Console\Command;
use Modules\Einsatzbericht\src\Services\ReportService;

class SendReportReminders extends Command
{
    protected $signature = 'einsatzbericht:remind';

    protected $description = 'Erinnert an offene und auf Freigabe wartende Einsatzberichte';

    public function handle(ReportService $reports): int
    {
        $n = app(ModuleRegistry::class)->isActive('einsatzbericht') ? $reports->remind() : 0;
        $this->info($n.' Berichte in der Erinnerung.');

        return self::SUCCESS;
    }
}
