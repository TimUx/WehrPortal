<?php

namespace Modules\Fahrtenbuch;

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
use Modules\Fahrtenbuch\src\Console\Commands\RemindOpenTrips;
use Modules\Fahrtenbuch\src\Livewire\FuelForm;
use Modules\Fahrtenbuch\src\Livewire\Overview;
use Modules\Fahrtenbuch\src\Livewire\Statistics;
use Modules\Fahrtenbuch\src\Livewire\TripForm;
use Modules\Fahrtenbuch\src\Livewire\VehicleLog;
use Modules\Fahrtenbuch\src\Models\Trip;
use Modules\Fahrtenbuch\src\Services\LogbookService;

/**
 * Fahrtenbuch: every trip of the vehicles of Fahrzeugbuchung and Fahrzeugverwaltung, recorded on
 * the phone, linked to bookings and Einsätze, with fuel stops,
 * consumption, statistics and export for Steuer and Versicherung.
 */
class FahrtenbuchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LogbookService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'fahrtenbuch');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([RemindOpenTrips::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(RemindOpenTrips::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('fahrtenbuch-manage', fn (User $user) => LogbookService::canManage($user));

        foreach (['overview' => Overview::class, 'trip-form' => TripForm::class, 'vehicle-log' => VehicleLog::class,
            'fuel-form' => FuelForm::class, 'statistics' => Statistics::class] as $name => $class) {
            Livewire::component('fahrtenbuch.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'fahrtenbuch',
            'Fahrtenbuch',
            'Elektronisches Fahrtenbuch für alle Feuerwehrfahrzeuge – rechtssicher, einfach und immer aktuell',
            roles: ['fahrtenbuchwart'],
            provider: self::class,
            route: 'fahrtenbuch.index',
        );

        // Start page: the own open trip, to end it with one tap
        app(DashboardRegistry::class)->register('fahrtenbuch', 'open', function (User $user) {
            $trip = LogbookService::canView($user) ? Trip::open()->with('vehicle')->where('driver_id', $user->id)->latest('started_at')->first() : null;

            return $trip ? view('fahrtenbuch::cards.open', compact('trip'))->render() : null;
        });

        // Jahresbericht: trips and kilometres per vehicle
        app(AnnualReportRegistry::class)->register('fahrtenbuch', 'Fahrten', function (int $year) {
            $trips = Trip::valid()->with('vehicle')->whereNotNull('end_km')->whereYear('started_at', $year)->get();

            return $trips->isEmpty() ? null : view('fahrtenbuch::annual.section', [
                'rows' => $trips->groupBy('vehicle_id')->map(fn ($t) => ['name' => $t->first()->vehicle?->name, 'trips' => $t->count(), 'km' => $t->sum(fn (Trip $trip) => $trip->distance() ?? 0)])->sortBy('name')->values(),
            ])->render();
        }, 60);

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('fahrtenbuch', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Fahrzeuge', 'fahrtenbuch.index', ['fahrtenbuch.vehicle', 'fahrtenbuch.trip']),
                ModuleNav::tab('Tanken', 'fahrtenbuch.fuel'),
                LogbookService::canManage($u) ? ModuleNav::tab('Auswertung', 'fahrtenbuch.statistics') : null,
            ],
        ]);
    }
}
