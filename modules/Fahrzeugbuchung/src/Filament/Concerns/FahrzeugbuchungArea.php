<?php

namespace Modules\Fahrzeugbuchung\src\Filament\Concerns;

use Modules\Fahrzeugbuchung\src\Filament\Pages\FahrzeugbuchungDashboard;
use UnitEnum;

// Resources, pages and widgets of the Fahrzeugbuchung area in /admin: grouped under it, visible to whoever may open it
trait FahrzeugbuchungArea
{
    public static function canAccess(): bool
    {
        return FahrzeugbuchungDashboard::canAccess() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return FahrzeugbuchungDashboard::getNavigationGroup();
    }
}
