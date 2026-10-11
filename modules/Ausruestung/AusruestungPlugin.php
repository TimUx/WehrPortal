<?php

namespace Modules\Ausruestung;

use Filament\Contracts\Plugin;
use Filament\Panel;

class AusruestungPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'ausruestung';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(in: __DIR__.'/src/Filament/Resources', for: 'Modules\\Ausruestung\\src\\Filament\\Resources')
            ->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Ausruestung\\src\\Filament\\Pages')
            ->discoverWidgets(in: __DIR__.'/src/Filament/Widgets', for: 'Modules\\Ausruestung\\src\\Filament\\Widgets');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
