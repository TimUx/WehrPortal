<?php

namespace Modules\Atemschutz;

use Filament\Contracts\Plugin;
use Filament\Panel;

class AtemschutzPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'atemschutz';
    }

    public function register(Panel $panel): void
    {
        $panel
            ->discoverResources(in: __DIR__.'/src/Filament/Resources', for: 'Modules\\Atemschutz\\src\\Filament\\Resources')
            ->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Atemschutz\\src\\Filament\\Pages');
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
