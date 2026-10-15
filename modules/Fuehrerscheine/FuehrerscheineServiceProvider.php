<?php

namespace Modules\Fuehrerscheine;

use App\Models\User;
use App\Services\BadgeRegistry;
use App\Services\CredentialRegistry;
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
use Modules\Fuehrerscheine\src\Console\Commands\SendLicenseReminders;
use Modules\Fuehrerscheine\src\Livewire\MyLicense;
use Modules\Fuehrerscheine\src\Livewire\Review;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Services\LicenseService;

class FuehrerscheineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LicenseService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'fuehrerscheine');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendLicenseReminders::class]);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');
            $schedule->command(SendLicenseReminders::class)->dailyAt('07:25')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('fuehrerscheine-manage', fn (User $user) => LicenseService::canManage($user));

        foreach (['my-license' => MyLicense::class, 'review' => Review::class] as $name => $class) {
            Livewire::component('fuehrerscheine.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'fuehrerscheine',
            'Führerscheine',
            'Führerscheinkontrolle per Foto statt Termin in der Wache, Klassen und Feuerwehr-Fahrberechtigung mit Fristen, wer welches Fahrzeug fahren darf',
            roles: ['fuehrerscheinpruefer'],
            provider: self::class,
            route: 'fuehrerscheine.index',
        );

        app(PersonnelFileRegistry::class)->register('fuehrerscheine', 'Führerschein', 'fuehrerscheine.personnel-file');

        // Fahrtenbuch and Fahrzeuge ask here who may drive what
        app(DriverRegistry::class)->register('fuehrerscheine',
            fn (User $driver, int $vehicleId) => app(LicenseService::class)->warning($driver, $vehicleId),
            fn (int $vehicleId) => app(LicenseService::class)->drivers($vehicleId),
        );

        // Kürzel next to the Kameraden: the valid classes that matter for Feuerwehr vehicles
        app(BadgeRegistry::class)->register('fuehrerscheine', fn (array $ids) => License::whereIn('user_id', $ids)->get()->filter->usable()
            ->mapWithKeys(fn (License $l) => [$l->user_id => array_values(array_map(fn ($c) => ['FW475' => 'FW4,75', 'FW75' => 'FW7,5'][$c] ?? $c,
                array_intersect($l->validClasses(), ['C1', 'C1E', 'C', 'CE', 'D1', 'D', 'FW475', 'FW75'])))])->filter()->all());

        // Personalakte → Nachweise: the checked Fahrerlaubnis
        $usable = fn (User $user) => ($l = License::where('user_id', $user->id)->first()) && $l->usable() ? $l : null;
        app(CredentialRegistry::class)->register('fuehrerscheine', 'Fahrerlaubnis',
            fn (User $user) => ($l = $usable($user)) ? view('fuehrerscheine::credentials.card', ['license' => $l])->render() : null,
            fn (User $user) => ($l = $usable($user)) ? view('fuehrerscheine::credentials.check', ['license' => $l])->render() : null,
        );

        // Start page: the own Führerschein (missing step, rejected, due) and for the Prüfer what waits
        app(DashboardRegistry::class)->register('fuehrerscheine', 'attention', function (User $user) {
            $license = License::where('user_id', $user->id)->first();
            $own = match (true) {
                $license?->status === 'rejected' => 'Führerschein zurückgewiesen – bitte nachreichen',
                (bool) $license?->documentExpired() => 'Dein Führerschein ist abgelaufen',
                (bool) $license?->checkDue() => 'Führerscheinprüfung fällig – bitte neue Fotos hochladen',
                default => null,
            };
            $pending = LicenseService::canManage($user) ? License::where('status', 'pending')->count() : 0;

            return $own || $pending ? view('fuehrerscheine::cards.attention', compact('own', 'pending'))->render() : null;
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('fuehrerscheine', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Mein Führerschein', 'fuehrerscheine.index'),
                LicenseService::canManage($u) ? ModuleNav::tab('Prüfen', 'fuehrerscheine.review') : null,
            ],
        ]);
    }
}
