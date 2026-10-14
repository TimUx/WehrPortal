<?php

namespace Modules\Fahrzeugbuchung;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Console\Commands\SendBookingEndReminders;
use Modules\Fahrzeugbuchung\src\Filament\Pages\FahrzeugbuchungDashboard;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingComments;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingDetail;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\DayList;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\MyBookings;
use Modules\Fahrzeugbuchung\src\Livewire\Calendar\BookingCalendar;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionDetail;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionIndex;
use Modules\Fahrzeugbuchung\src\Livewire\Monitor\StationBoard;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingComment;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Policies\BookingCommentPolicy;
use Modules\Fahrzeugbuchung\src\Policies\BookingPolicy;
use Modules\Fahrzeugbuchung\src\Policies\VehiclePolicy;

class FahrzeugbuchungServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'fahrzeugbuchung');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([
            SendBookingEndReminders::class,
        ]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // Remind bookers an hour before their booking ends
            $schedule->command(SendBookingEndReminders::class)->everyMinute()->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::policy(Booking::class, BookingPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(BookingComment::class, BookingCommentPolicy::class);

        Gate::define('decide-bookings', fn (User $user) => $user->canDecide());

        // Explicit aliases so existing Blade wire tags keep working after the namespace move
        Livewire::component('booking.booking-form', BookingForm::class);
        Livewire::component('booking.booking-detail', BookingDetail::class);
        Livewire::component('booking.booking-comments', BookingComments::class);
        Livewire::component('booking.day-list', DayList::class);
        Livewire::component('booking.my-bookings', MyBookings::class);
        Livewire::component('calendar.booking-calendar', BookingCalendar::class);
        Livewire::component('decisions.decision-index', DecisionIndex::class);
        Livewire::component('decisions.decision-detail', DecisionDetail::class);
        Livewire::component('monitor.station-board', StationBoard::class);

        app(ModuleRegistry::class)->register(
            'fahrzeugbuchung',
            'Fahrzeugbuchung',
            'Fahrzeugverwaltung und Buchung für die Feuerwehr',
            roles: ['entscheider'],
            provider: self::class,
            route: 'fahrzeugbuchung.calendar',
        );

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('fahrzeugbuchung', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Kalender', 'fahrzeugbuchung.calendar'),
                ModuleNav::tab('Meine Buchungen', 'fahrzeugbuchung.my-bookings'),
                $u->canDecide() ? ModuleNav::tab('Entscheidungen', 'fahrzeugbuchung.decisions.index', ['fahrzeugbuchung.decisions.*'], BookingDecision::pending()->count()) : null,
            ],
            'manage' => $u->is_admin ? FahrzeugbuchungDashboard::getUrl() : null,
        ]);
    }
}
