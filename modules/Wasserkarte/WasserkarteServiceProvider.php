<?php

namespace Modules\Wasserkarte;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Wasserkarte\src\Console\Commands\SendWaterReminders;
use Modules\Wasserkarte\src\Livewire\Inspections;
use Modules\Wasserkarte\src\Livewire\MapView;
use Modules\Wasserkarte\src\Livewire\SourceDetail;
use Modules\Wasserkarte\src\Livewire\SourceForm;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Services\WaterService;

/**
 * Wasserkarte & Hydranten: all Löschwasserquellen on a map with status and performance data,
 * inspections and defect reports; at an Einsatz the nearest usable ones are shown with it.
 */
class WasserkarteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaterService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'wasserkarte');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendWaterReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendWaterReminders::class)->dailyAt('06:55')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('wasserkarte-manage', fn (User $user) => WaterService::canManage($user));

        foreach (['map-view' => MapView::class, 'source-detail' => SourceDetail::class, 'source-form' => SourceForm::class, 'inspections' => Inspections::class] as $name => $class) {
            Livewire::component('wasserkarte.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'wasserkarte',
            'Wasserkarte & Hydranten',
            'Digitale Hydrantenkarte mit Live-Status, Durchflussmengen und automatischer Einbindung in den Einsatz',
            roles: ['hydrantenwart'],
            provider: self::class,
            route: 'wasserkarte.index',
        );

        // Start page: defective sources and overdue inspections for the Hydrantenwarte
        app(DashboardRegistry::class)->register('wasserkarte', 'status', function (User $user) {
            if (! WaterService::canManage($user)) {
                return null;
            }
            $defective = Source::active()->where('status', 'defective')->count();
            $overdue = Source::active()->where('next_inspection_on', '<', now('Europe/Berlin')->toDateString())->count();

            return $defective + $overdue === 0 ? null : view('wasserkarte::cards.status', compact('defective', 'overdue'))->render();
        });
    }
}
