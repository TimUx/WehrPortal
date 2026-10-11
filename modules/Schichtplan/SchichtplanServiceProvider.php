<?php

namespace Modules\Schichtplan;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Schichtplan\src\Console\Commands\SendShiftReminders;
use Modules\Schichtplan\src\Livewire\PlanCreate;
use Modules\Schichtplan\src\Livewire\PlanEditor;
use Modules\Schichtplan\src\Livewire\PlanList;
use Modules\Schichtplan\src\Livewire\SignupBoard;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;
use Modules\Schichtplan\src\Policies\PlanPolicy;
use Modules\Schichtplan\src\Services\PlanMailer;
use Modules\Schichtplan\src\Services\PlanService;
use Modules\Schichtplan\src\Services\ShiftPlanner;
use Modules\Schichtplan\src\Services\SignupService;

class SchichtplanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([PlanService::class, ShiftPlanner::class, PlanMailer::class, SignupService::class] as $service) {
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

        $this->commands([SendShiftReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // The day before: each Kamerad gets their confirmed shifts once
            $schedule->command(SendShiftReminders::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::policy(Plan::class, PlanPolicy::class);

        Livewire::component('schichtplan.plan-list', PlanList::class);
        Livewire::component('schichtplan.plan-create', PlanCreate::class);
        Livewire::component('schichtplan.plan-editor', PlanEditor::class);
        Livewire::component('schichtplan.signup-board', SignupBoard::class);

        app(ModuleRegistry::class)->register(
            'schichtplan',
            'Schichtplan',
            'Arbeitsdienste, Feste und Stände: Schichten einteilen oder Mitglieder selbst eintragen lassen',
            roles: ['planer'],
            provider: self::class,
            route: 'schichtplan.index',
        );

        // Start page: plans to sign up for, and requests waiting for the planer or Stationsverantwortung
        app(DashboardRegistry::class)->register('schichtplan', 'signup', function (User $user) {
            $open = app(SignupService::class)->openPlansFor($user);
            $requests = Assignment::where('status', Assignment::REQUESTED)->with('plan')->get()
                ->filter(fn (Assignment $a) => SignupService::canApprove($user, $a))->count();

            return $open->isEmpty() && $requests === 0 ? null : view('schichtplan::cards.signup', compact('open', 'requests'))->render();
        });
    }
}
