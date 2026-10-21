<?php

namespace Extensions\Locations;

use App\Models\User;
use Extensions\Locations\Console\EnsureLocationsCommand;
use Extensions\Locations\Console\InventoryModuleRolesCommand;
use Extensions\Locations\Filament\LocationsPlugin;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Support\AssignsLocation;
use Filament\Facades\Filament;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Several Standorte (fire stations) in one instance. Personnel, vehicles, equipment, reports … carry
 * ext_location_id; users only see their Standorte, Standort-Admins manage their own, global admins
 * (users.is_admin) everything. Switched off with LOCATIONS_ENABLED=false.
 */
class LocationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/locations.php', 'locations');
        $this->mergeConfigFrom(__DIR__.'/config/protection.php', 'locations.protection');
        $this->app->singleton(LocationAccess::class);

        $this->app->booting(function () {
            if (config('locations.enabled')) {
                Filament::getPanel('admin')?->plugin(LocationsPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(LocationModuleRoles::class)->configuredModules();

        if (! config('locations.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'locations');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([EnsureLocationsCommand::class, InventoryModuleRolesCommand::class]);
        }

        Gate::define('manage-location', fn (User $user, Location $location) => $this->app->make(LocationAccess::class)->canManage($user, $location));

        Event::listen(MigrationsStarted::class, fn () => AssignsLocation::reset());

        foreach ((array) config('locations.models') as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $class::addGlobalScope(new LocationScope);
            $class::creating(fn ($model) => AssignsLocation::creating($model));
            $class::updating(fn ($model) => AssignsLocation::updating($model));
        }
    }
}
