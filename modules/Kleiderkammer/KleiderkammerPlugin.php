<?php

namespace Modules\Kleiderkammer;

use Filament\Contracts\Plugin;
use Filament\Panel;

class KleiderkammerPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'kleiderkammer';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(
                in: __DIR__.'/src/Filament/Resources',
                for: 'Modules\\Kleiderkammer\\src\\Filament\\Resources',
            )
            ->discoverWidgets(
                in: __DIR__.'/src/Filament/Widgets',
                for: 'Modules\\Kleiderkammer\\src\\Filament\\Widgets',
            );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
