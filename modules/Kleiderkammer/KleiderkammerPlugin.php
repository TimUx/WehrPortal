<?php

namespace Modules\Kleiderkammer;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Modules\Kleiderkammer\src\Filament\Pages\ScanPage;

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
            ->discoverResources(in: __DIR__.'/src/Filament/Resources', for: 'Modules\\Kleiderkammer\\src\\Filament\\Resources')
            ->discoverPages(in: __DIR__.'/src/Filament/Pages', for: 'Modules\\Kleiderkammer\\src\\Filament\\Pages')
            ->discoverWidgets(in: __DIR__.'/src/Filament/Widgets', for: 'Modules\\Kleiderkammer\\src\\Filament\\Widgets')
            // Loaded in <head> rather than inside the Livewire page body, so it always executes.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => Blade::render("@vite('modules/Kleiderkammer/resources/js/scanner.js')"),
                scopes: ScanPage::class,
            );
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
