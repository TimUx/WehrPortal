<?php

namespace Modules\Atemschutz;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\BadgeRegistry;
use App\Services\CredentialRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Atemschutz\src\Console\Commands\SendAtemschutzReminders;
use Modules\Atemschutz\src\Livewire\Board;
use Modules\Atemschutz\src\Livewire\MyStatus;
use Modules\Atemschutz\src\Livewire\Overview;
use Modules\Atemschutz\src\Livewire\Wearers;
use Modules\Atemschutz\src\Models\Operation;
use Modules\Atemschutz\src\Models\TeamMember;
use Modules\Atemschutz\src\Models\Training;
use Modules\Atemschutz\src\Models\Wearer;
use Modules\Atemschutz\src\Services\AtemschutzPdf;
use Modules\Atemschutz\src\Services\MonitorService;
use Modules\Atemschutz\src\Services\ReadinessService;

/**
 * Atemschutzüberwachung: the operative monitoring of the Trupps at the Einsatz (time, pressures,
 * Rückweg, warnings) and the administration of the Atemschutzgeräteträger (G26.3, Unterweisung,
 * Belastungsübung) in one module. Every Trupp is documented and can go with the Einsatzbericht.
 */
class AtemschutzServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReadinessService::class);
        $this->app->singleton(MonitorService::class);
        $this->app->singleton(AtemschutzPdf::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'atemschutz');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendAtemschutzReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // Deadlines of the Geräteträger, once a day
            $schedule->command(SendAtemschutzReminders::class)->dailyAt('07:15')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('atemschutz-manage', fn (User $user) => MonitorService::canManage($user));
        Gate::define('atemschutz-monitor', fn (User $user) => MonitorService::canMonitor($user));
        Gate::define('atemschutz-monitor-operation', fn (User $user, Operation $operation) => MonitorService::canMonitorOperation($user, $operation));

        foreach (['overview' => Overview::class, 'board' => Board::class, 'wearers' => Wearers::class, 'my-status' => MyStatus::class] as $name => $class) {
            Livewire::component('atemschutz.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'atemschutz',
            'Atemschutzüberwachung',
            'Digitale Atemschutzüberwachung – Echtzeit-Truppmonitoring, G26.3-Tauglichkeitsverwaltung und FwDV 7 konforme Dokumentation',
            roles: ['atemschutzwart', 'ueberwacher'],
            provider: self::class,
            route: 'atemschutz.index',
        );

        app(PersonnelFileRegistry::class)->register('atemschutz', 'Atemschutz', 'atemschutz.personnel-file');

        // Kürzel next to the Kameraden: einsatzbereit as AGT, and as CSA-Träger
        app(BadgeRegistry::class)->register('atemschutz', fn (array $ids) => app(ReadinessService::class)->forWearers(Wearer::active()->whereIn('user_id', $ids)->get())
            ->map(fn ($s) => array_values(array_filter([$s['ready'] ? 'AGT' : null, ($s['csa']['ready'] ?? false) ? 'CSA' : null])))->filter()->all());

        // Personalakte → Nachweise: the Atemschutzpass of everyone in the pool (dates only, never findings)
        $state = fn (User $user) => ($wearer = Wearer::where('user_id', $user->id)->where('is_active', true)->first()) ? app(ReadinessService::class)->forWearer($wearer) : null;
        app(CredentialRegistry::class)->register('atemschutz', 'Atemschutzpass',
            fn (User $user) => ($s = $state($user)) ? view('atemschutz::credentials.pass', ['state' => $s, 'wear' => app(ReadinessService::class)->wearTime($user)])->render() : null,
            fn (User $user) => ($s = $state($user)) ? view('atemschutz::credentials.pass-check', ['state' => $s])->render() : null,
        );

        // Jahresbericht: Überwachungen and Trupps of the year, Nachweise, the pool as of today
        app(AnnualReportRegistry::class)->register('atemschutz', 'Atemschutz', function (int $year) {
            $operations = Operation::whereYear('started_at', $year)->get()->groupBy('kind')
                ->mapWithKeys(fn ($rows, $kind) => [(Operation::KINDS[$kind] ?? $kind) => $rows->count()]);
            $members = TeamMember::whereHas('team', fn ($q) => $q->whereNotNull('started_at')->whereYear('started_at', $year))->get(['team_id', 'user_id']);
            $trainings = Training::whereYear('done_on', $year)->get()->groupBy('kind')
                ->mapWithKeys(fn ($rows, $kind) => [Training::KINDS[$kind] ?? $kind => $rows->count()]);
            if ($operations->isEmpty() && $trainings->isEmpty()) {
                return null;
            }

            return view('atemschutz::annual.section', [
                'operations' => $operations, 'teams' => $members->pluck('team_id')->unique()->count(),
                'people' => $members->pluck('user_id')->filter()->unique()->count(), 'trainings' => $trainings,
                'pool' => $year === (int) now('Europe/Berlin')->year ? app(ReadinessService::class)->poolCounts() : null,
                'wear' => app(ReadinessService::class)->wearTimeAll($year),
            ])->render();
        }, 50);

        // Start page: the pool for Atemschutzwarte and Überwacher, the own status for Geräteträger
        app(DashboardRegistry::class)->register('atemschutz', 'pool', function (User $user) {
            $readiness = app(ReadinessService::class);
            $own = Wearer::where('user_id', $user->id)->where('is_active', true)->first();
            $pool = MonitorService::canMonitor($user) ? $readiness->poolCounts() : null;
            $state = $own ? $readiness->forWearer($own) : null;

            return $pool || $state ? view('atemschutz::cards.pool', compact('pool', 'state'))->render() : null;
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('atemschutz', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Überwachung', 'atemschutz.index', ['atemschutz.board']),
                MonitorService::canManage($u) ? ModuleNav::tab('Geräteträger', 'atemschutz.wearers') : null,
            ],
        ]);
    }
}
