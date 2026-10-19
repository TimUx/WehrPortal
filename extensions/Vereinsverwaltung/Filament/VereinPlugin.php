<?php

namespace Extensions\Vereinsverwaltung\Filament;

use Extensions\Vereinsverwaltung\Filament\Resources\AssociationResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class VereinPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'vereinsverwaltung';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([AssociationResource::class]);
    }

    public function boot(Panel $panel): void {}
}
