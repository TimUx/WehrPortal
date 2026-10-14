<?php

namespace Modules\Ausruestung\src\Filament\Concerns;

use Modules\Ausruestung\src\Filament\Pages\AusruestungDashboard;
use UnitEnum;

// Resources, pages and widgets of the Ausrüstung area in /admin: grouped under it, visible to whoever may open it
trait AusruestungArea
{
    public static function canAccess(): bool
    {
        return AusruestungDashboard::canAccess() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return AusruestungDashboard::getNavigationGroup();
    }
}
