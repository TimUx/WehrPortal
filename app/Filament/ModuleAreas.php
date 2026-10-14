<?php

namespace App\Filament;

use App\Models\User;
use Filament\Facades\Filament;

final class ModuleAreas
{
    /** @return list<class-string<ModuleArea>> the entry pages of the module areas $user may open, in navigation order */
    public static function for(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        $panel = Filament::getPanel('admin');
        $groups = $panel->getNavigationGroups();

        return collect($panel->getPages())
            ->filter(fn (string $page) => is_subclass_of($page, ModuleArea::class) && $page::allows($user))
            ->sortBy(fn (string $page) => array_search($page::getNavigationGroup(), $groups, true))
            ->values()
            ->all();
    }
}
