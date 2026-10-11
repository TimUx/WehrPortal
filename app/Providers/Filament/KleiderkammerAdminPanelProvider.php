<?php

namespace App\Providers\Filament;

use App\Services\OrganizationSettings;
use App\Support\BrandColor;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Kleiderkammer\KleiderkammerPlugin;
use Modules\Kleiderkammer\src\Filament\Pages\DataTransferPage;
use Modules\Kleiderkammer\src\Filament\Pages\InventoryReportPage;
use Modules\Kleiderkammer\src\Filament\Pages\ScanPage;
use Modules\Kleiderkammer\src\Filament\Widgets\OverviewWidget;

class KleiderkammerAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('kleiderkammer')
            ->path('admin/kleiderkammer')
            ->authGuard('web')
            ->login(fn () => redirect()->route('auth.magic-link.form'))
            ->loginRouteSlug('anmelden')
            ->colors([
                'primary' => BrandColor::palette(),
            ])
            ->brandName(fn () => app(OrganizationSettings::class)->shortName().' – Kleiderkammer')
            ->navigationItems([
                NavigationItem::make('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-squares-2x2')
                    ->sort(-99),
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-squares-2x2'),
            ])
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.kleiderkammer-sidebar-end'))
            // Loaded in <head> rather than inside the Livewire page body, so it always executes.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => Blade::render("@vite('modules/Kleiderkammer/resources/js/scanner.js')"),
                scopes: ScanPage::class,
            )
            ->plugin(KleiderkammerPlugin::make())
            ->pages([
                Pages\Dashboard::class,
                ScanPage::class,
                InventoryReportPage::class,
                DataTransferPage::class,
            ])
            ->widgets([
                OverviewWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
