<?php

namespace Modules\Maengelmanagement;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Maengelmanagement\src\Console\Commands\SendDefectReminders;
use Modules\Maengelmanagement\src\Filament\Pages\MaengelmanagementDashboard;
use Modules\Maengelmanagement\src\Livewire\DefectDetail;
use Modules\Maengelmanagement\src\Livewire\DefectList;
use Modules\Maengelmanagement\src\Livewire\ReportForm;
use Modules\Maengelmanagement\src\Models\Defect;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Maengelmanagement\src\Services\DefectPhotoService;
use Modules\Maengelmanagement\src\Services\DefectService;

class MaengelmanagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DefectPhotoService::class);
        $this->app->singleton(DefectService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'maengelmanagement');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendDefectReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendDefectReminders::class)->dailyAt('07:15')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Livewire::component('maengelmanagement.defect-list', DefectList::class);
        Livewire::component('maengelmanagement.report-form', ReportForm::class);
        Livewire::component('maengelmanagement.defect-detail', DefectDetail::class);

        app(ModuleRegistry::class)->register(
            'maengelmanagement',
            'Mängelmanagement',
            'Mängel per QR-Code-Scan melden, bearbeiten und bis zur Erledigung verfolgen',
            roles: ['verantwortlicher'],
            provider: self::class,
            route: 'maengelmanagement.index',
        );

        // Start page: Rückfragen waiting for me, defects assigned to me, my open reports; the overall picture for Verantwortliche
        app(DashboardRegistry::class)->register('maengelmanagement', 'status', function (User $user) {
            $questions = Defect::active()->whereNotNull('question_open_at')->where('reporter_id', $user->id)->get(['id', 'title']);
            $assigned = Defect::active()->where('assigned_to', $user->id)->count();
            $own = Defect::active()->where('reporter_id', $user->id)->count();
            $manager = $user->canManageMaengel();
            if ($questions->isEmpty() && ! $assigned && ! $own && ! $manager) {
                return null;
            }

            return view('maengelmanagement::cards.status', [
                'questions' => $questions, 'assigned' => $assigned, 'own' => $own, 'manager' => $manager,
                'open' => $manager ? Defect::active()->count() : 0,
                'critical' => $manager ? Defect::active()->where('urgency', 'critical')->count() : 0,
                'unassigned' => $manager ? Defect::active()->whereNull('assigned_to')->count() : 0,
            ])->render();
        });

        // Jahresbericht: reported, closed, how fast, still open at the end of the year
        app(AnnualReportRegistry::class)->register('maengelmanagement', 'Mängel', function (int $year) {
            $reported = Defect::with(['device', 'vehicle'])->whereYear('created_at', $year)->get();
            $resolved = Defect::where('status', 'resolved')->whereYear('resolved_at', $year)->get(['created_at', 'resolved_at']);
            if ($reported->isEmpty() && $resolved->isEmpty()) {
                return null;
            }
            $end = Carbon::create($year, 12, 31, 23, 59, 59, 'Europe/Berlin')->utc();

            return view('maengelmanagement::annual.section', [
                'reported' => $reported->count(), 'resolved' => $resolved->count(),
                'avgDays' => $resolved->isEmpty() ? null : $resolved->avg(fn ($d) => $d->created_at->diffInHours($d->resolved_at) / 24),
                'openAtEnd' => Defect::where('created_at', '<=', $end)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>', $end))->count(),
                'bySubject' => $reported->filter(fn ($d) => $d->device_id || $d->vehicle_id)->countBy(fn ($d) => $d->subject())->sortDesc()->take(10),
            ])->render();
        }, 64);

        // Bestand: the devices a defect can be reported for, with their open defects
        app(InventoryRegistry::class)->register('maengelmanagement', 'Mängelmanagement', function (string $term, User $user) {
            if (! $user->canUseModule('maengelmanagement')) {
                return null;
            }
            $like = '%'.$term.'%';

            return Device::active()->withCount(['defects' => fn ($q) => $q->active()])
                ->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('location', 'ilike', $like))
                ->orderBy('name')->limit(50)->get()
                ->map(fn (Device $d) => ['title' => $d->label(), 'code' => $d->code, 'place' => $d->location,
                    'state' => $d->defects_count ? $d->defects_count.' offene'.($d->defects_count === 1 ? 'r Mangel' : ' Mängel') : 'keine Mängel',
                    'tone' => $d->defects_count ? 'amber' : 'green', 'url' => route('maengelmanagement.report', ['objekt' => $d->code])]);
        }, order: 60);

        // A scan of a device label opens the report form with that device preselected
        app(ScanRegistry::class)->register('maengelmanagement', function (string $code): ?string {
            return Device::active()->where('code', $code)->exists()
                ? route('maengelmanagement.report', ['objekt' => $code])
                : null;
        });

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('maengelmanagement', fn (User $u) => [
            'tabs' => [ModuleNav::tab('Mängel', 'maengelmanagement.index', ['maengelmanagement.show'])],
            'action' => ModuleNav::action('Mangel melden', 'maengelmanagement.report'),
            'manage' => $u->canManageMaengel() ? MaengelmanagementDashboard::getUrl() : null,
        ]);
    }
}
