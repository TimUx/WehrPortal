<?php

namespace Modules\Unterweisungen;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\BadgeRegistry;
use App\Services\DashboardRegistry;
use App\Services\DriverRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Unterweisungen\src\Console\Commands\SendInstructionReminders;
use Modules\Unterweisungen\src\Livewire\MyInstructions;
use Modules\Unterweisungen\src\Livewire\Overview;
use Modules\Unterweisungen\src\Livewire\SessionDetail;
use Modules\Unterweisungen\src\Livewire\SessionForm;
use Modules\Unterweisungen\src\Livewire\Topics;
use Modules\Unterweisungen\src\Models\Attendance;
use Modules\Unterweisungen\src\Models\Session;
use Modules\Unterweisungen\src\Services\InstructionService;

class UnterweisungenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InstructionService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'unterweisungen');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendInstructionReminders::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');
            $schedule->command(SendInstructionReminders::class)->dailyAt('07:30')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('unterweisungen-manage', fn (User $user) => InstructionService::canManage($user));

        foreach (['my-instructions' => MyInstructions::class, 'overview' => Overview::class, 'topics' => Topics::class, 'session-form' => SessionForm::class, 'session-detail' => SessionDetail::class] as $name => $class) {
            Livewire::component('unterweisungen.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'unterweisungen',
            'Unterweisungen',
            'Unterweisungen und Einweisungen mit Fristen, Unterschrift der Teilnehmer auf dem Tablet und Nachweis',
            roles: ['unterweisungsbeauftragter'],
            provider: self::class,
            route: 'unterweisungen.index',
        );

        app(PersonnelFileRegistry::class)->register('unterweisungen', 'Unterweisungen', 'unterweisungen.personnel-file');

        // Fahrtenbuch: a vehicle that needs an Einweisung
        app(DriverRegistry::class)->register('unterweisungen', fn (User $driver, int $vehicleId) => app(InstructionService::class)->vehicleWarning($driver, $vehicleId));

        // Kürzel of the valid Unterweisungen next to the Kameraden
        app(BadgeRegistry::class)->register('unterweisungen', fn (array $ids) => app(InstructionService::class)->badges($ids));

        // Start page: own Unterweisungen due or missing, open signatures; for the Beauftragte how many are behind
        app(DashboardRegistry::class)->register('unterweisungen', 'attention', function (User $user) {
            $service = app(InstructionService::class);
            $own = $service->forUser($user)->filter(fn ($r) => $r['required'] && $r['state'] !== 'ok')
                ->map(fn ($r) => $r['topic']->name.': '.InstructionService::STATES[$r['state']])->values()->all();
            if (($unsigned = Attendance::where('user_id', $user->id)->whereNull('signed_at')->count()) > 0) {
                $own[] = $unsigned.' Unterweisung'.($unsigned === 1 ? '' : 'en').' bitte unterschreiben';
            }
            $manage = 0;
            if (InstructionService::canManage($user)) {
                $m = $service->matrix();
                $manage = $m['users']->filter(fn ($u) => collect($m['cells'][$u->id] ?? [])->contains(fn ($c) => $c['required'] && in_array($c['state'], ['expired', 'missing'], true)))->count();
            }

            return $own || $manage ? view('unterweisungen::cards.attention', compact('own', 'manage'))->render() : null;
        });

        // Jahresbericht: Unterweisungen held in the year
        app(AnnualReportRegistry::class)->register('unterweisungen', 'Unterweisungen', function (int $year) {
            $sessions = Session::with('topic')->withCount('attendances')->whereYear('held_on', $year)->get();

            return $sessions->isEmpty() ? null : view('unterweisungen::annual.section', [
                'sessions' => $sessions->count(), 'attendances' => $sessions->sum('attendances_count'),
                'byTopic' => $sessions->groupBy(fn ($s) => $s->topic->name)->map->sum('attendances_count'),
            ])->render();
        }, 42);

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('unterweisungen', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Meine Unterweisungen', 'unterweisungen.index'),
                InstructionService::canManage($u) ? ModuleNav::tab('Übersicht', 'unterweisungen.overview', ['unterweisungen.session']) : null,
                InstructionService::canManage($u) ? ModuleNav::tab('Arten', 'unterweisungen.topics') : null,
            ],
            'action' => InstructionService::canManage($u) ? ModuleNav::action('Durchführen', 'unterweisungen.new') : null,
        ]);
    }
}
