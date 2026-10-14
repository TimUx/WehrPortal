<?php

namespace App\Filament;

use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

// The start page of a module's area in the one admin panel (/admin/<key>). Its navigation group holds
// the module's resources and pages; allows() decides who sees the area, so Kleiderwarte, Gerätewarte etc.
// get into /admin with just their module, while admins see everything.
abstract class ModuleDashboard extends Dashboard
{
    protected static ?string $navigationLabel = 'Übersicht';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = -10;

    /** Whether $user (active) manages this module in /admin */
    abstract public static function allows(User $user): bool;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->is_active && static::allows($user);
    }

    /** The module areas of the admin panel that $user may open, in navigation order */
    public static function accessibleFor(User $user): array
    {
        $panel = Filament::getPanel('admin');

        return collect($panel->getPages())
            ->filter(fn (string $page) => is_subclass_of($page, self::class) && $user->is_active && $page::allows($user))
            ->sortBy(fn (string $page) => array_search($page::getNavigationGroup(), $panel->getNavigationGroups(), true))
            ->values()
            ->all();
    }
}
