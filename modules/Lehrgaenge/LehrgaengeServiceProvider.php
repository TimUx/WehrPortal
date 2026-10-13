<?php

namespace Modules\Lehrgaenge;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\BadgeRegistry;
use App\Services\DashboardRegistry;
use App\Services\HoursRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Lehrgaenge\src\Console\Commands\ImportCatalog;
use Modules\Lehrgaenge\src\Console\Commands\RemindRefresh;
use Modules\Lehrgaenge\src\Console\Commands\RemindUpcomingCourses;
use Modules\Lehrgaenge\src\Livewire\CourseList;
use Modules\Lehrgaenge\src\Livewire\MemberProfile;
use Modules\Lehrgaenge\src\Livewire\Members;
use Modules\Lehrgaenge\src\Livewire\MyCourses;
use Modules\Lehrgaenge\src\Livewire\Qualifications;
use Modules\Lehrgaenge\src\Livewire\Registrations;
use Modules\Lehrgaenge\src\Livewire\ResultImports;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Models\Session;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use Modules\Lehrgaenge\src\Services\RegistrationService;

class LehrgaengeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RegistrationService::class);
        $this->app->singleton(CatalogImporter::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'lehrgaenge');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([ImportCatalog::class, RemindUpcomingCourses::class, RemindRefresh::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // The Lehrgänge without fixed date from fox112, so the Kameraden always see what is offered
            $schedule->command(ImportCatalog::class)->dailyAt('06:30')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
            // Kameraden hear 14 days ahead that their Lehrgang begins
            $schedule->command(RemindUpcomingCourses::class)->dailyAt('07:20')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
            // Refreshers that become due
            $schedule->command(RemindRefresh::class)->dailyAt('07:22')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('manage-lehrgaenge', fn (User $user) => $user->canManageLehrgaenge());
        Gate::define('view-lehrgaenge', fn (User $user) => $user->canViewLehrgaenge());

        foreach (['my-courses' => MyCourses::class, 'registrations' => Registrations::class, 'members' => Members::class, 'member-profile' => MemberProfile::class, 'course-list' => CourseList::class, 'qualifications' => Qualifications::class, 'result-imports' => ResultImports::class] as $name => $class) {
            Livewire::component('lehrgaenge.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'lehrgaenge',
            'Lehrgänge & Qualifikationen',
            'Interesse an Lehrgängen des Kreises melden, Anmeldung über die Ausbildungsleitung, absolvierte Lehrgänge',
            roles: ['ausbildungsleiter', 'wehrführung'],
            provider: self::class,
            route: 'lehrgaenge.index',
        );

        // Start page: the next own Lehrgang; Interesse and recent Absagen for whoever gets the notifications about them
        // (the Ausbildungsleiter, the admins only when there is none) – so card and bell always agree
        app(DashboardRegistry::class)->register('lehrgaenge', 'attention', function (User $user) {
            $next = Record::with('course')->where('user_id', $user->id)->where('status', 'confirmed')
                ->whereDate('starts_on', '>=', RegistrationService::today())->orderBy('starts_on')->first();
            $todo = $user->canManageLehrgaenge() && app(RegistrationService::class)->managers()->contains('id', $user->id) ? [
                'requested' => Record::where('status', 'requested')->count(),
                'cancelled' => Record::where('status', 'cancelled')->where('cancelled_at', '>=', now()->subDays(7))->count(),
                'suggested' => Course::whereNotNull('suggested_at')->count(),
                'gone' => Course::onList()->whereNotNull('gone_since')->count(),
            ] : [];

            // Own refreshers due within REFRESH_WARN_DAYS or overdue
            $refresh = Record::with('course')->where('user_id', $user->id)->where('status', 'completed')->whereHas('course', fn ($q) => $q->whereNotNull('repeat_months'))->get()
                ->filter(fn (Record $r) => in_array($r->refreshState(), ['soon', 'expired'], true))->values();

            return ! $next && array_sum($todo) === 0 && $refresh->isEmpty() ? null : view('lehrgaenge::cards.attention', compact('next', 'todo', 'refresh'))->render();
        });

        // Personalakte → Lehrgänge: the Kamerad's absolvierte and confirmed Lehrgänge
        app(PersonnelFileRegistry::class)->register('lehrgaenge', 'Lehrgänge', 'lehrgaenge.personnel-file');

        // Stundennachweis: the Lehrgangstage with time (confirmed or absolviert, up to today)
        app(HoursRegistry::class)->register('lehrgaenge', 'Aus- und Fortbildung', fn (array $ids, Carbon $from, Carbon $to) => Session::with('record.course')
            ->whereHas('record', fn ($q) => $q->whereIn('user_id', $ids)->whereIn('status', ['confirmed', 'completed']))
            ->whereBetween('date', [$from->toDateString(), min($to->toDateString(), RegistrationService::today()->toDateString())])
            ->whereNotNull('starts_at')->whereNotNull('ends_at')->get()
            ->map(fn (Session $s) => ['user_id' => $s->record->user_id, 'date' => $s->date, 'title' => 'Lehrgang: '.$s->record->course?->name,
                'minutes' => max(0, (int) Carbon::parse($s->starts_at)->diffInMinutes(Carbon::parse($s->ends_at), false))]));

        // Kürzel next to the Kameraden: absolvierte Lehrgänge with a Kürzel that are still valid
        app(BadgeRegistry::class)->register('lehrgaenge', fn (array $ids) => Record::with('course')->where('status', 'completed')->whereIn('user_id', $ids)
            ->whereHas('course', fn ($q) => $q->whereNotNull('short'))->get()->reject(fn (Record $r) => $r->refreshState() === 'expired')
            ->groupBy('user_id')->map(fn ($rows) => $rows->pluck('course.short')->unique()->values()->all())->all());

        // Jahresbericht: Lehrgänge completed in the year
        app(AnnualReportRegistry::class)->register('lehrgaenge', 'Aus- und Fortbildung', function (int $year) {
            $records = Record::with('course')->where('status', 'completed')->whereYear('completed_on', $year)->get();

            return $records->isEmpty() ? null : view('lehrgaenge::annual.section', [
                'courses' => $records->groupBy(fn (Record $r) => $r->course?->name ?? 'Lehrgang')->map->count()->sortDesc(),
                'people' => $records->pluck('user_id')->unique()->count(),
            ])->render();
        }, 40);
    }
}
