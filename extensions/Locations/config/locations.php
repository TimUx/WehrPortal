<?php

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Extensions\Vereinsverwaltung\Models\Member;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Kalender\src\Models\Event;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Maengelmanagement\src\Models\Defect;

return [
    // false: the extension is not loaded at all (no scopes, no admin pages)
    'enabled' => (bool) env('LOCATIONS_ENABLED', true),

    // Explicit CLI summaries only; no existing module views or raw-object authorization.
    'leadership_enabled' => (bool) env('LOCATIONS_LEADERSHIP_ENABLED', false),

    // Stock quantities only; this does not certify a module-wide Kleiderkammer adapter.
    'clothing_stock_enabled' => (bool) env('LOCATIONS_CLOTHING_STOCK_ENABLED', false),

    'clothing_personal_enabled' => (bool) env('LOCATIONS_CLOTHING_PERSONAL_ENABLED', false),

    // Staged, opt-in adapter. Unsupported modules fail closed; entry is not object authorization.
    'scoped_role_modules' => array_values(array_unique(array_filter(
        array_map(fn (string $module): string => strtolower(trim($module)), explode(',', (string) env('LOCATIONS_SCOPED_ROLE_MODULES', ''))),
        fn (string $module): bool => $module !== '',
    ))),

    // Models that carry users.ext_location_id style columns: they are filtered to the Standorte of the
    // signed-in user and get the current Standort on create. Rows without a Standort (NULL) are shared.
    'models' => [
        User::class,
        Group::class,
        MemberFunction::class,
        Member::class,
        Vehicle::class,
        Equipment::class,
        Category::class,
        Item::class,
        Report::class,
        Defect::class,
        Event::class,
    ],
];
