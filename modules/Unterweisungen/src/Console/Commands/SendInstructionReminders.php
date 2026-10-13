<?php

namespace Modules\Unterweisungen\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Unterweisungen\src\Services\InstructionService;

class SendInstructionReminders extends Command
{
    protected $signature = 'unterweisungen:remind';

    protected $description = 'Remind Kameraden of Unterweisungen running out';

    public function handle(InstructionService $instructions): int
    {
        $this->info($instructions->remind().' Kameraden erinnert.');

        return self::SUCCESS;
    }
}
