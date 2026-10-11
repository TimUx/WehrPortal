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
use Modules\Maengelmanagement\MaengelmanagementPlugin;

class MaengelmanagementAdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('maengelmanagement')
            ->path('admin/maengelmanagement')
            ->authGuard('web')
            ->login(fn () => redirect()->route('auth.magic-link.form'))
            ->loginRouteSlug('anmelden')
            ->colors([
                'primary' => BrandColor::palette(),
            ])
            ->brandName(fn () => app(OrganizationSettings::class)->shortName().' – Mängelmanagement')
            ->navigationItems([
                NavigationItem::make('Zur App')
                    ->url(fn () => route('maengelmanagement.index'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(-100),
                NavigationItem::make('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-squares-2x2')
                    ->sort(-99),
            ])
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_END, fn () => view('filament.maengelmanagement-sidebar-end'))
            ->userMenuItems([
                MenuItem::make()
                    ->label('Zur App')
                    ->url(fn () => route('maengelmanagement.index'))
                    ->icon('heroicon-o-arrow-left'),
            ])
            ->plugin(MaengelmanagementPlugin::make())
            ->pages([
                Pages\Dashboard::class,
            ])
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
