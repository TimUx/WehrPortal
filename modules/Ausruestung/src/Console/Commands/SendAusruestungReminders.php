<?php

namespace Modules\Ausruestung\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Ausruestung\src\Services\ReminderService;

class SendAusruestungReminders extends Command
{
    protected $signature = 'ausruestung:send-reminders';

    protected $description = 'Daily digest of due inspections, expiring lifetimes, recalls and damages; reminders for overdue loans';

    public function handle(ReminderService $reminders): int
    {
        $result = $reminders->run();

        $this->info(($result['digest'] ? 'Digest sent' : 'No digest').', '.$result['overdue_loans'].' overdue loan reminder(s)');

        return self::SUCCESS;
    }
}
