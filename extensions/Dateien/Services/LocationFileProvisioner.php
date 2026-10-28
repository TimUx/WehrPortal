<?php

namespace Extensions\Dateien\Services;

use Extensions\Dateien\Models\Space;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocationFileProvisioner
{
    public function ensureAll(): void
    {
        if (! $this->enabled()) {
            return;
        }

        Location::query()->orderBy('id')->each(fn (Location $location) => $this->ensure($location));
    }

    public function ensure(Location $location): ?Space
    {
        if (! $this->enabled()) {
            return null;
        }

        return DB::transaction(function () use ($location): ?Space {
            $location = Location::query()->lockForUpdate()->find($location->getKey());
            if (! $location) {
                return null;
            }

            return Space::query()->firstOrCreate(
                ['ext_location_id' => $location->getKey(), 'is_location_default' => true],
                ['name' => $location->name],
            );
        });
    }

    private function enabled(): bool
    {
        return config('locations.enabled')
            && Schema::hasTable('x_locations')
            && Schema::hasColumn('x_files_spaces', 'is_location_default')
            && DB::table('modules')->where('key', 'dateien')->where('is_active', true)->exists();
    }
}
