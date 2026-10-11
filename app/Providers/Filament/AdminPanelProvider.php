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
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authGuard('web')
            ->login(fn () => redirect()->route('auth.magic-link.form'))
            ->loginRouteSlug('anmelden')
            ->colors([
                'primary' => BrandColor::palette(),
            ])
            ->brandName(fn () => app(OrganizationSettings::class)->shortName().' Admin')
            ->navigationItems([
                NavigationItem::make('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(-100),
            ])
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.sidebar-end'))
            ->userMenuItems([
                MenuItem::make()
                    ->label('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-arrow-left'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([])
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
