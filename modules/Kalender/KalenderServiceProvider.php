<?php

namespace Modules\Kalender;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Kalender\src\Console\Commands\RemindPendingResponses;
use Modules\Kalender\src\Console\Commands\SyncCalendarSources;
use Modules\Kalender\src\Livewire\CalendarView;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\Sources;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Policies\EventPolicy;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\FeedService;
use Modules\Kalender\src\Services\IcsExporter;
use Modules\Kalender\src\Services\IcsParser;
use Modules\Kalender\src\Services\SourceSync;

class KalenderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([EventService::class, IcsParser::class, IcsExporter::class, SourceSync::class, FeedService::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'kalender');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SyncCalendarSources::class, RemindPendingResponses::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // External calendars are pulled in every hour
            $schedule->command(SyncCalendarSources::class)->hourly()->withoutOverlapping()->appendOutputTo($output);

            // Kameraden who have not answered are reminded once, shortly before the deadline or the event
            $schedule->command(RemindPendingResponses::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::policy(Event::class, EventPolicy::class);

        Livewire::component('kalender.calendar-view', CalendarView::class);
        Livewire::component('kalender.event-form', EventForm::class);
        Livewire::component('kalender.event-detail', EventDetail::class);
        Livewire::component('kalender.sources', Sources::class);

        app(ModuleRegistry::class)->register(
            'kalender',
            'Kalender & Termine',
            'Übungen, Dienste, Veranstaltungen und Wartungen mit Rückmeldung',
            roles: ['terminverwalter'],
            provider: self::class,
            route: 'kalender.index',
        );

        // The next event on the personal start page, with the Kamerad's own Rückmeldung
        app(DashboardRegistry::class)->register('kalender', 'next-event', function (User $user) {
            $event = Event::visibleTo($user)->upcoming()->with('groups')->first();

            return $event ? view('kalender::cards.next-event', [
                'event' => $event,
                'response' => $event->responses()->where('user_id', $user->id)->first(),
            ])->render() : null;
        });
    }
}
