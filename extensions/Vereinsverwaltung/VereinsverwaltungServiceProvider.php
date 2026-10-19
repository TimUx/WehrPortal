<?php

namespace Extensions\Vereinsverwaltung;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Services\AssociationRegistry;
use App\Support\ModuleNav;
use Extensions\Vereinsverwaltung\Http\Controllers\MembershipApplicationController;
use Extensions\Vereinsverwaltung\Filament\VereinPlugin;
use Extensions\Vereinsverwaltung\Livewire\Board;
use Extensions\Vereinsverwaltung\Services\AssociationAccess;
use Extensions\Vereinsverwaltung\Services\MembershipService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class VereinsverwaltungServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/vereinsverwaltung.php', 'vereinsverwaltung');
        $this->app->singleton(MembershipService::class);
        $this->app->singleton(AssociationAccess::class);
        $this->app->booting(function () {
            if (config('vereinsverwaltung.enabled')) {
                Filament::getPanel('admin')?->plugin(VereinPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        if (! config('vereinsverwaltung.enabled')) {
            return;
        }

        $base = __DIR__;
        $this->loadMigrationsFrom($base.'/database/migrations');
        $this->loadViewsFrom($base.'/resources/views', 'vereinsverwaltung');

        Gate::define('manage-verein', fn (User $user) => app(AssociationAccess::class)->canManage($user));
        Livewire::component('vereinsverwaltung.board', Board::class);

        RateLimiter::for('vereinsverwaltung-application', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }

        app(ModuleRegistry::class)->register(
            'verein',
            'Vereinsverwaltung',
            'Vereinsmitgliedschaften, Beitragsdaten und SEPA-Mandate dokumentieren',
            roles: ['vereinsvorstand'],
            provider: self::class,
            route: 'verein.index',
        );
        app(ModuleRegistry::class)->navigation('verein', fn (User $user) => [
            'tabs' => [ModuleNav::tab('Mitglieder & Anträge', 'verein.index')],
        ]);
        app(AssociationRegistry::class)->register(
            fn (User $user) => app(MembershipService::class)->isLinkedMember($user),
            fn (User $user, bool $isMember) => app(MembershipService::class)->setPersonnelMembership($user, $isMember),
        );
    }
}
