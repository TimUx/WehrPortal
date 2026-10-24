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
use Modules\Kleiderkammer\src\Filament\Pages\ScanPage;
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
            // The name in the top bar, with the app version behind it in small
            ->brandLogo(fn () => view('filament.brand', ['name' => app(OrganizationSettings::class)->shortName().' Admin']))
            ->brandLogoHeight('auto')
            ->favicon(asset('images/wehrportal-icon.svg'))
            ->navigationItems([
                NavigationItem::make('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(-100),
            ])
            // Under the name in the user menu: the e-mail address
            ->renderHook(PanelsRenderHook::USER_MENU_PROFILE_AFTER, fn () => view('filament.user-menu-email'))
            // The red Scan button for Kleiderwarte on the phone, on the pages of the Kleiderkammer
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('kleiderkammer::partials.scan-button', ['scanUrl' => ScanPage::getUrl()]))
            ->userMenuItems([
                MenuItem::make()
                    ->label('Zur Übersicht')
                    ->url(fn () => route('home'))
                    ->icon('heroicon-o-arrow-left')
                    ->sort(1),
                MenuItem::make()
                    ->label('Meine Personalakte')
                    ->url(fn () => route('profile'))
                    ->icon('heroicon-o-user-circle')
                    ->sort(2),
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
