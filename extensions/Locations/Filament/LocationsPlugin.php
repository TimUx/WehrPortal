<?php

namespace Extensions\Locations\Filament;

use Extensions\Locations\Filament\Resources\LocationResource;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;

class LocationsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'locations';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([LocationResource::class])
            ->pages([MyLocationPage::class, StandardRolesPage::class, LeadershipOverviewPage::class])
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER, fn () => view('locations::partials.switcher'));
    }

    public function boot(Panel $panel): void {}
}
