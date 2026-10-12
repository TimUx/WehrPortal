<?php

namespace Modules\Objektplaene\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Objektplaene\src\Services\ObjectPlanService;

class SendReviewReminders extends Command
{
    protected $signature = 'objektplaene:remind';

    protected $description = 'Tell the Planverwalter which objects are due for a review of their plans';

    public function handle(ObjectPlanService $plans): int
    {
        $this->info('Fällige Objekte: '.$plans->remind());

        return self::SUCCESS;
    }
}
