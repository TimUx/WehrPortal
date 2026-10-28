<?php

namespace Extensions\Locations\Services;

use App\Models\Group;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StandardDepartments
{
    public const NAMES = [
        'Einsatzabteilung',
        'Jugendfeuerwehr',
        'Kinderfeuerwehr',
        'Alters- und Ehrenabteilung',
        'Vorstand',
        'Verein',
    ];

    public function ensureFor(Location $location): void
    {
        if (! Schema::hasColumn('groups', 'ext_location_id')) {
            return;
        }

        DB::transaction(function () use ($location): void {
            if (! Location::query()->lockForUpdate()->whereKey($location->getKey())->exists()) {
                return;
            }

            foreach (self::NAMES as $index => $name) {
                Group::withoutGlobalScopes()->firstOrCreate(
                    ['ext_location_id' => $location->getKey(), 'name' => $name],
                    ['sort_order' => ($index + 1) * 10],
                );
            }
        });
    }
}
