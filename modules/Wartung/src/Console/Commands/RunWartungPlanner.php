<?php

namespace Modules\Wartung\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Wartung\src\Services\WartungService;

class RunWartungPlanner extends Command
{
    protected $signature = 'wartung:plan';

    protected $description = 'Open the Wartungen that are within their Voranzeige and mail the Gerätewarte what is newly due or overdue';

    public function handle(WartungService $service): int
    {
        $opened = $service->plan();
        $mailed = $service->remind();
        $this->info("Wartungen geöffnet: {$opened}, gemeldet: {$mailed}");

        return self::SUCCESS;
    }
}
