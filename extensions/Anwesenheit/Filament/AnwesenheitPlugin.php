<?php

namespace Extensions\Anwesenheit\Filament;

use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AnwesenheitPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'anwesenheit';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([SettingsPage::class])->resources([TemplateResource::class]);
    }

    public function boot(Panel $panel): void {}
}
