<?php

namespace Modules\Maengelmanagement;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MaengelmanagementPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'maengelmanagement';
    }

    public function register(Panel $panel): void
    {
        $panel->discoverResources(
            in: __DIR__.'/src/Filament/Resources',
            for: 'Modules\\Maengelmanagement\\src\\Filament\\Resources',
        );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
