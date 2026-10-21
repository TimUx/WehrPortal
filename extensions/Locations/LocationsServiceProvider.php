<?php

namespace Extensions\Locations;

use App\Models\User;
use Extensions\Locations\Console\AssignAttendanceCommand;
use Extensions\Locations\Console\AssignClothingCommand;
use Extensions\Locations\Console\AssignClothingStockCommand;
use Extensions\Locations\Console\AuditClothingCommand;
use Extensions\Locations\Console\AuditLocationDataCommand;
use Extensions\Locations\Console\EnsureLocationsCommand;
use Extensions\Locations\Console\InventoryModuleRolesCommand;
use Extensions\Locations\Filament\LocationsPlugin;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\ClothingStockAccess;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Support\AssignsLocation;
use Filament\Facades\Filament;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\StockAccess;

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
        $this->app->singleton(ClothingStockAccess::class);
        $this->app->bind(StockAccess::class, fn ($app) => $app->make(ClothingStockAccess::class));

        $this->app->booting(function () {
            if (config('locations.enabled')) {
                Filament::getPanel('admin')->plugin(LocationsPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(LocationModuleRoles::class)->configuredModules();
        if (config('locations.clothing_stock_enabled')) {
            $this->app->make(ClothingStockAccess::class)->validateConfiguration();
        }

        if (! config('locations.enabled')) {
            return;
        }

        foreach (['creating', 'updating', 'deleting'] as $event) {
            Stock::{$event}(fn (Stock $stock) => $this->app->make(ClothingStockAccess::class)->guardMutation($stock, $event));
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'locations');

        View::composer('components.layouts.app', function (\Illuminate\View\View $view) {
            $view->with('appContextViews', [
                ...($view->getData()['appContextViews'] ?? []),
                'locations::partials.app-context',
            ]);
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([EnsureLocationsCommand::class, InventoryModuleRolesCommand::class, AuditLocationDataCommand::class, AuditClothingCommand::class, AssignAttendanceCommand::class, AssignClothingCommand::class, AssignClothingStockCommand::class]);
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
