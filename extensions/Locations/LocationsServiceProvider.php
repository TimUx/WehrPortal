<?php

namespace Extensions\Locations;

use App\Models\User;
use Extensions\Locations\Console\AssignAttendanceCommand;
use Extensions\Locations\Console\AssignClothingCommand;
use Extensions\Locations\Console\AssignClothingStockCommand;
use Extensions\Locations\Console\AuditClothingCommand;
use Extensions\Locations\Console\AuditLocationDataCommand;
use Extensions\Locations\Console\CheckOperationalReadinessCommand;
use Extensions\Locations\Console\CorrectClothingHistoryCommand;
use Extensions\Locations\Console\EnsureLocationsCommand;
use Extensions\Locations\Console\InventoryModuleRolesCommand;
use Extensions\Locations\Console\LeadershipCommand;
use Extensions\Locations\Console\TransferClothingStockCommand;
use Extensions\Locations\Filament\LocationsPlugin;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Services\ClothingPersonalAccess;
use Extensions\Locations\Services\ClothingStockAccess;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ScopedAnnouncementService;
use Extensions\Locations\Support\AssignsLocation;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemCategory;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemTreatment;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\MemberSize;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Models\Stock;
use Modules\Kleiderkammer\src\Services\PersonalAccess;
use Modules\Kleiderkammer\src\Services\StockAccess;
use Modules\Maengelmanagement\src\Models\Device as DefectDevice;
use Modules\Mitteilungen\src\Services\AnnouncementService;

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
        $this->app->singleton(ClothingPersonalAccess::class);
        $this->app->bind(PersonalAccess::class, fn ($app) => $app->make(ClothingPersonalAccess::class));

        $this->app->booting(function () {
            if (config('locations.enabled')) {
                Filament::getPanel('admin')->plugin(LocationsPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        $this->app->make(LocationModuleRoles::class)->configuredModules();
        if ($this->app->make(LocationModuleRoles::class)->enabled('mitteilungen')) {
            $this->app->singleton(AnnouncementService::class, ScopedAnnouncementService::class);
        }
        if (config('locations.clothing_stock_enabled')) {
            $this->app->make(ClothingStockAccess::class)->validateConfiguration();
        }
        if (config('locations.clothing_personal_enabled')) {
            $this->app->make(ClothingPersonalAccess::class)->validateConfiguration();
        }

        if (! config('locations.enabled')) {
            return;
        }

        foreach (['creating', 'updating', 'deleting'] as $event) {
            Stock::{$event}(fn (Stock $stock) => $this->app->make(ClothingStockAccess::class)->guardMutation($stock, $event));
        }
        foreach ([IssuedItem::class, Item::class, MemberSize::class, EquipmentMessage::class, ItemTreatment::class] as $class) {
            $class::addGlobalScope('clothing-personal', function ($query) {
                $access = $this->app->make(ClothingPersonalAccess::class);
                $access->scope($query, $query->getModel(), $access->currentActor());
            });
            foreach (['creating', 'updating', 'deleting'] as $event) {
                $class::{$event}(fn ($model) => $this->app->make(ClothingPersonalAccess::class)->guardMutation($model, $event));
            }
        }
        Item::restoring(fn ($model) => $this->app->make(ClothingPersonalAccess::class)->guardMutation($model, 'restoring'));
        foreach ([
            ItemType::class,
            ItemCategory::class,
            ItemGroup::class,
            Size::class,
        ] as $class) {
            foreach (['creating', 'updating', 'deleting'] as $event) {
                $class::{$event}(function () {
                    $access = $this->app->make(ClothingPersonalAccess::class);
                    $access->authorizeTransfer($access->currentActor());
                });
            }
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
            $this->commands([EnsureLocationsCommand::class, InventoryModuleRolesCommand::class, AuditLocationDataCommand::class, AuditClothingCommand::class, AssignAttendanceCommand::class, AssignClothingCommand::class, AssignClothingStockCommand::class, CheckOperationalReadinessCommand::class, LeadershipCommand::class, TransferClothingStockCommand::class, CorrectClothingHistoryCommand::class]);
        }

        Gate::define('manage-location', fn (User $user, Location $location) => $this->app->make(LocationAccess::class)->canManage($user, $location));

        Event::listen(MigrationsStarted::class, fn () => AssignsLocation::reset());

        User::created(function (User $user): void {
            if ($user->ext_location_id) {
                DB::table('x_location_user')->insertOrIgnore([
                    'location_id' => $user->ext_location_id,
                    'user_id' => $user->getKey(),
                    'role' => 'member',
                ]);
            }

            $this->app->make(LocationAccess::class)->forget($user);
        });

        foreach ((array) config('locations.models') as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $class::addGlobalScope(new LocationScope);
            $class::creating(fn ($model) => AssignsLocation::creating($model));
            $class::updating(fn ($model) => AssignsLocation::updating($model));
        }

        foreach (['creating', 'updating'] as $event) {
            DefectDevice::{$event}(function (DefectDevice $device): void {
                if (! Auth::hasUser() || ! config('locations.enabled')) {
                    return;
                }

                $user = Auth::user();
                $locationId = $device->getAttribute('ext_location_id');
                if (! $user instanceof User || ! $user->canManageMaengelAt($locationId === null ? null : (int) $locationId)) {
                    throw new AuthorizationException('Kein Recht, Geräte an diesem Standort zu verwalten.');
                }
            });
        }
    }
}
