<?php

namespace Modules\Lehrgaenge;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Lehrgaenge\src\Console\Commands\ImportCatalog;
use Modules\Lehrgaenge\src\Console\Commands\RemindUpcomingCourses;
use Modules\Lehrgaenge\src\Livewire\CourseList;
use Modules\Lehrgaenge\src\Livewire\MemberProfile;
use Modules\Lehrgaenge\src\Livewire\Members;
use Modules\Lehrgaenge\src\Livewire\MyCourses;
use Modules\Lehrgaenge\src\Livewire\Registrations;
use Modules\Lehrgaenge\src\Models\Record;
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

        $this->commands([ImportCatalog::class, RemindUpcomingCourses::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // The Lehrgänge without fixed date from fox112, so the Kameraden always see what is offered
            $schedule->command(ImportCatalog::class)->dailyAt('06:30')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
            // Kameraden hear 14 days ahead that their Lehrgang begins
            $schedule->command(RemindUpcomingCourses::class)->dailyAt('07:20')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('manage-lehrgaenge', fn (User $user) => $user->canManageLehrgaenge());
        Gate::define('view-lehrgaenge', fn (User $user) => $user->canViewLehrgaenge());

        foreach (['my-courses' => MyCourses::class, 'registrations' => Registrations::class, 'members' => Members::class, 'member-profile' => MemberProfile::class, 'course-list' => CourseList::class] as $name => $class) {
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

        // Start page: the next own Lehrgang; for the Ausbildungsleiter Interesse and recent Absagen
        app(DashboardRegistry::class)->register('lehrgaenge', 'attention', function (User $user) {
            $next = Record::with('course')->where('user_id', $user->id)->where('status', 'confirmed')
                ->whereDate('starts_on', '>=', RegistrationService::today())->orderBy('starts_on')->first();
            $todo = $user->canManageLehrgaenge() ? [
                'requested' => Record::where('status', 'requested')->count(),
                'cancelled' => Record::where('status', 'cancelled')->where('cancelled_at', '>=', now()->subDays(7))->count(),
            ] : [];

            return ! $next && array_sum($todo) === 0 ? null : view('lehrgaenge::cards.attention', compact('next', 'todo'))->render();
        });

        // Personalakte → Lehrgänge: the Kamerad's absolvierte and confirmed Lehrgänge
        app(PersonnelFileRegistry::class)->register('lehrgaenge', 'Lehrgänge', 'lehrgaenge.personnel-file');

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
