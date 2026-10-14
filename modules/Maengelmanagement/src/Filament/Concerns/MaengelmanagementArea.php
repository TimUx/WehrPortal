<?php

namespace Modules\Maengelmanagement\src\Filament\Concerns;

use Modules\Maengelmanagement\src\Filament\Pages\MaengelmanagementDashboard;
use UnitEnum;

// Resources, pages and widgets of the Mängelmanagement area in /admin: grouped under it, visible to whoever may open it
trait MaengelmanagementArea
{
    public static function canAccess(): bool
    {
        return MaengelmanagementDashboard::canAccess() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MaengelmanagementDashboard::getNavigationGroup();
    }
}
