<?php

namespace Modules\Uebungsdienste;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Uebungsdienste\src\Console\Commands\SyncCalendar;
use Modules\Uebungsdienste\src\Livewire\Planner;
use Modules\Uebungsdienste\src\Livewire\ServiceForm;
use Modules\Uebungsdienste\src\Models\Service;
use Modules\Uebungsdienste\src\Services\ServicePlan;

/**
 * Übungsdienstplaner for the Feuerwehr: the Wehrführung plans services for the Einsatzabteilung.
 */
class UebungsdiensteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ServicePlan::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'uebungsdienste');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SyncCalendar::class]);

        // Dienste not in the Kalender yet (e.g. the Kalender was switched on later)
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SyncCalendar::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Livewire::component('uebungsdienste.planner', Planner::class);
        Livewire::component('uebungsdienste.service-form', ServiceForm::class);

        Gate::define('plan-uebungsdienste', fn (User $user) => app(ServicePlan::class)->canPlan($user));

        app(ModuleRegistry::class)->register(
            'uebungsdienste',
            'Übungsdienstplaner',
            'Übungsdienste standortbezogen für passende Abteilungen planen',
            provider: self::class,
            route: 'uebungsdienste.index',
            roles: ['wehrführung', 'jugendwart', 'kinderwart', 'gemeindejugendwart', 'gemeindekinderwart'],
            audience: fn (User $user) => app(ServicePlan::class)->canPlan($user),
            audienceLabel: 'Übungsdienstleitung',
        );

        // Start page: the next Übungsdienst. The Wehrführung (module access) sees topic and who is responsible,
        // Other group members see date, time and place – like the Kalender, never the topic
        app(DashboardRegistry::class)->register('uebungsdienste', 'next', function (User $user) {
            $planner = $user->can('plan-uebungsdienste');
            $next = $planner ? app(ServicePlan::class)->nextForPlanner($user) : app(ServicePlan::class)->nextFor($user);

            return $next ? view('uebungsdienste::cards.next', ['service' => $next, 'planner' => $planner])->render() : null;
        }, everyone: true);

        // Jahresbericht: how many Übungsdienste were planned (the topics stay in the plan)
        app(AnnualReportRegistry::class)->register('uebungsdienste', 'Übungsdienste', function (int $year) {
            $count = Service::whereYear('date', $year)->count();

            return $count === 0 ? null : '<div class="ar-grid"><div class="ar-tile"><span>Übungsdienste geplant</span><strong>'.$count.'</strong></div>'
                .'<div class="ar-tile"><span>davon stattgefunden</span><strong>'.Service::whereYear('date', $year)->whereDate('date', '<', ServicePlan::today())->count().'</strong></div></div>';
        }, 35);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('uebungsdienste', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Übungsdienste', 'uebungsdienste.index', ['uebungsdienste.edit', 'uebungsdienste.create']),
                $u->can('plan-uebungsdienste') ? ModuleNav::tab('Orte & Kleidung', 'uebungsdienste.places') : null,
            ],
        ]);
    }
}
