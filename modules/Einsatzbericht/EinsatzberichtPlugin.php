<?php

namespace Modules\Einsatzbericht;

use Filament\Contracts\Plugin;
use Filament\Panel;

class EinsatzberichtPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'einsatzbericht';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Einsatzbericht\\src\\Filament\\Pages');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
