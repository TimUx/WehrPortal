<?php

namespace Modules\Atemschutz\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Atemschutz\src\Services\ReadinessService;

class SendAtemschutzReminders extends Command
{
    protected $signature = 'atemschutz:remind';

    protected $description = 'Remind Atemschutzgeräteträger and Atemschutzwarte of running-out G26.3, Belastungsübung and Unterweisung';

    public function handle(ReadinessService $readiness): int
    {
        $this->info('Erinnert: '.$readiness->remind());

        return self::SUCCESS;
    }
}
