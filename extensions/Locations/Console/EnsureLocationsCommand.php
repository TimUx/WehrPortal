<?php

namespace Extensions\Locations\Console;

use Extensions\Locations\Models\Location;
use Illuminate\Console\Command;

class EnsureLocationsCommand extends Command
{
    protected $signature = 'app:ensure-locations';

    protected $description = 'Stellt sicher, dass mindestens ein (Haupt-)Standort existiert';

    public function handle(): int
    {
        $existed = Location::query()->exists();
        $default = Location::default();

        $this->info(($existed ? 'Hauptstandort: ' : 'Angelegt: ').$default->name);

        return self::SUCCESS;
    }
}
