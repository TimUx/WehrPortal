<?php

namespace Extensions\Oidc\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class OidcPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'oidc';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([OidcSettingsPage::class]);
    }

    public function boot(Panel $panel): void {}
}
