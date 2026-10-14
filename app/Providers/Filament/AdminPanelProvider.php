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
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Modules\Ausruestung\AusruestungPlugin;
use Modules\Einsatzbericht\EinsatzberichtPlugin;
use Modules\Fahrzeugbuchung\FahrzeugbuchungPlugin;
use Modules\Kleiderkammer\KleiderkammerPlugin;
use Modules\Maengelmanagement\MaengelmanagementPlugin;
use Modules\Personalverwaltung\PersonalverwaltungPlugin;

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
            // One panel for everyone who manages something: admins see all of it, module managers
            // (Kleiderwart, Wehrführung, Personalverwalter …) only their module's group (App\Filament\ModuleArea).
            ->navigationGroups(['Fahrzeugbuchung', 'Kleiderkammer', 'Ausrüstung', 'Mängelmanagement', 'Einsatzbericht', 'Personalverwaltung'])
            ->plugins([
                FahrzeugbuchungPlugin::make(),
                KleiderkammerPlugin::make(),
                AusruestungPlugin::make(),
                MaengelmanagementPlugin::make(),
                EinsatzberichtPlugin::make(),
                PersonalverwaltungPlugin::make(),
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
