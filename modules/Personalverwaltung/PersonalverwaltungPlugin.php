<?php

namespace Modules\Personalverwaltung;

use Filament\Contracts\Plugin;
use Filament\Panel;

class PersonalverwaltungPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'personalverwaltung';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Personalverwaltung\\src\\Filament\\Pages');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
