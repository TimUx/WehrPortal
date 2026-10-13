<?php

namespace Modules\Schluessel\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Schluessel\src\Services\KeyService;

class SendKeyReminders extends Command
{
    protected $signature = 'schluessel:remind';

    protected $description = 'Remind Kameraden of keys past their return date (once each)';

    public function handle(KeyService $keys): int
    {
        $this->info('Erinnert: '.$keys->remind());

        return self::SUCCESS;
    }
}
