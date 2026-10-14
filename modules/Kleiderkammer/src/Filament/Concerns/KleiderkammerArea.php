<?php

namespace Modules\Kleiderkammer\src\Filament\Concerns;

use Modules\Kleiderkammer\src\Filament\Pages\KleiderkammerDashboard;
use UnitEnum;

// Resources, pages and widgets of the Kleiderkammer area in /admin: grouped under it, visible to whoever may open it
trait KleiderkammerArea
{
    public static function canAccess(): bool
    {
        return KleiderkammerDashboard::canAccess() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return KleiderkammerDashboard::getNavigationGroup();
    }
}
