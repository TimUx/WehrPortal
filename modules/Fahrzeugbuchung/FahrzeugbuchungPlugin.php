<?php

namespace Modules\Fahrzeugbuchung;

use Filament\Contracts\Plugin;
use Filament\Panel;

class FahrzeugbuchungPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'fahrzeugbuchung';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/src/Filament/Resources',
                for: 'Modules\\Fahrzeugbuchung\\src\\Filament\\Resources',
            )
            ->discoverWidgets(
                in: __DIR__.'/src/Filament/Widgets',
                for: 'Modules\\Fahrzeugbuchung\\src\\Filament\\Widgets',
            );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
