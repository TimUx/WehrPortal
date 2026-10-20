<?php

namespace Extensions\Mitteilungen\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;

class MitteilungenChannelsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'mitteilungen-channels';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([ChannelsSettingsPage::class]);
    }

    public function boot(Panel $panel): void {}
}
