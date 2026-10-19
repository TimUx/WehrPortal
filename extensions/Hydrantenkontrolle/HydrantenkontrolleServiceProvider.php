<?php

namespace Extensions\Hydrantenkontrolle;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Extensions\Hydrantenkontrolle\Livewire\HydrantForm;
use Extensions\Hydrantenkontrolle\Livewire\HydrantHistory;
use Extensions\Hydrantenkontrolle\Livewire\HydrantIndex;
use Extensions\Hydrantenkontrolle\Livewire\YearOverview;
use Extensions\Hydrantenkontrolle\Models\Hydrant;
use Extensions\Hydrantenkontrolle\Models\Inspection;
use Extensions\Hydrantenkontrolle\Models\InspectionYear;
use Extensions\Hydrantenkontrolle\Scopes\HydrantenkontrolleScope;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Support\AssignsLocation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class HydrantenkontrolleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/hydrantenkontrolle.php', 'hydrantenkontrolle');
    }

    public function boot(): void
    {
        if (! config('hydrantenkontrolle.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'hydrantenkontrolle');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        foreach ([Hydrant::class, InspectionYear::class, Inspection::class] as $class) {
            $class::addGlobalScope(new HydrantenkontrolleScope);
        }

        if (config('locations.enabled')) {
            foreach ([Hydrant::class, InspectionYear::class] as $class) {
                $class::creating(fn ($model) => AssignsLocation::creating($model));
                $class::updating(fn ($model) => AssignsLocation::updating($model));
            }
        }

        Gate::define('manage-hydrantenkontrolle', fn (User $user) => InspectionService::canManage($user));
        foreach (['hydrant-index' => HydrantIndex::class, 'hydrant-form' => HydrantForm::class, 'hydrant-history' => HydrantHistory::class, 'year-overview' => YearOverview::class] as $name => $class) {
            Livewire::component('hydrantenkontrolle.'.$name, $class);
        }

        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register(
            'hydrantenkontrolle',
            'Hydrantenkontrolle',
            'Hydrantenstammdaten, fortlaufende Jahreskontrollen und PDF-Berichte',
            roles: ['hydrantenwart', InspectionService::ROLE_CROSS_LOCATION],
            provider: self::class,
            route: 'hydrantenkontrolle.index',
        );
        $registry->navigation('hydrantenkontrolle', fn (User $user) => [
            'tabs' => [
                ModuleNav::tab('Jahreskontrollen', 'hydrantenkontrolle.index', ['hydrantenkontrolle.year']),
                ModuleNav::tab('Hydranten', 'hydrantenkontrolle.hydrants', ['hydrantenkontrolle.create', 'hydrantenkontrolle.edit']),
            ],
        ]);
    }
}
