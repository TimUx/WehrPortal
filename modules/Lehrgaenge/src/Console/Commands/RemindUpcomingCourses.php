<?php

namespace Modules\Lehrgaenge\src\Console\Commands;

use Illuminate\Console\Command;
use Modules\Lehrgaenge\src\Services\RegistrationService;

class RemindUpcomingCourses extends Command
{
    protected $signature = 'lehrgaenge:remind-upcoming';

    protected $description = 'Remind Kameraden 14 days before their confirmed Lehrgang starts';

    public function handle(RegistrationService $registrations): int
    {
        $this->info($registrations->remindUpcoming().' reminder(s) sent');

        return self::SUCCESS;
    }
}
