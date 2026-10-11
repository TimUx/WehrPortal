<?php

namespace Modules\Schichtplan;

use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Livewire\PlanEditor;
use Modules\Schichtplan\src\Livewire\PlanList;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Policies\PlanPolicy;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;

class SchichtplanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([PlanService::class, ShiftPlanner::class, PlanMailer::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'schichtplan');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::policy(Plan::class, PlanPolicy::class);

        Livewire::component('schichtplan.plan-list', PlanList::class);
        Livewire::component('schichtplan.plan-create', PlanCreate::class);
        Livewire::component('schichtplan.plan-editor', PlanEditor::class);

        app(ModuleRegistry::class)->register(
            'schichtplan',
            'Schichtplan',
            'Schichten für Feste und Stände planen und automatisch gerecht verteilen',
            roles: ['planer'],
            provider: self::class,
            route: 'schichtplan.index',
        );
    }
}
