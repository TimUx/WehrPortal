<?php

namespace Modules\Kleiderkammer\src\Filament\Concerns;

use Modules\Kleiderkammer\src\Filament\Pages\KleiderkammerDashboard;
use Modules\Kleiderkammer\src\Filament\Resources\ItemCategoryResource;
use Modules\Kleiderkammer\src\Filament\Resources\ItemTypeResource;
use Modules\Kleiderkammer\src\Services\PersonalAccess;
use UnitEnum;

// Resources, pages and widgets of the Kleiderkammer area in /admin: grouped under it, visible to whoever may open it
trait KleiderkammerArea
{
    public static function canAccess(): bool
    {
        $access = app(PersonalAccess::class);
        if ($access->enabled() && in_array(static::class, [
            ItemCategoryResource::class,
            ItemTypeResource::class,
        ], true) && ! $access->canManageCatalog(auth()->user())) {
            return false;
        }

        return KleiderkammerDashboard::canAccess() && parent::canAccess();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return KleiderkammerDashboard::getNavigationGroup();
    }
}
