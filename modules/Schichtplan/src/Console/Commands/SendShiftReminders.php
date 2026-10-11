<?php

namespace Modules\Schichtplan\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Schichtplan\src\Services\SignupService;

class SendShiftReminders extends Command
{
    protected $signature = 'schichtplan:remind';

    protected $description = 'Remind everyone of their confirmed shifts starting within the next 24 hours';

    public function handle(SignupService $signups): int
    {
        $this->info('Erinnert: '.$signups->remind());

        return self::SUCCESS;
    }
}
