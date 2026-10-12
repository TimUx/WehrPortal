<?php

namespace Modules\Schichtplan\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Schichtplan\src\Services\ChangeNotifier;

class NotifyShiftChanges extends Command
{
    protected $signature = 'schichtplan:notify-changes';

    protected $description = 'Tell everyone whose shifts the planer changed after publishing';

    public function handle(ChangeNotifier $changes): int
    {
        $this->info('Benachrichtigt: '.$changes->run());

        return self::SUCCESS;
    }
}
