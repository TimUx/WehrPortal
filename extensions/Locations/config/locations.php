<?php

return [
    // false: the extension is not loaded at all (no scopes, no admin pages)
    'enabled' => (bool) env('LOCATIONS_ENABLED', true),

    // Models that carry users.ext_location_id style columns: they are filtered to the Standorte of the
    // signed-in user and get the current Standort on create. Rows without a Standort (NULL) are shared.
    'models' => [
        \App\Models\User::class,
        \App\Models\Group::class,
        \App\Models\MemberFunction::class,
        \Extensions\Vereinsverwaltung\Models\Member::class,
        \Modules\Fahrzeugbuchung\src\Models\Vehicle::class,
        \Modules\Ausruestung\src\Models\Equipment::class,
        \Modules\Ausruestung\src\Models\Category::class,
        \Modules\Kleiderkammer\src\Models\Item::class,
        \Modules\Einsatzbericht\src\Models\Report::class,
        \Modules\Maengelmanagement\src\Models\Defect::class,
        \Modules\Kalender\src\Models\Event::class,
    ],
];
