<?php

namespace Modules\Ausruestung;

use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Ausruestung\src\Console\Commands\SendAusruestungReminders;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Livewire\EquipmentDetail;
use Modules\Ausruestung\src\Livewire\EquipmentList;
use Modules\Ausruestung\src\Livewire\MyLoans;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Services\AusruestungMailer;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\InventoryExport;
use Modules\Ausruestung\src\Services\LoadPlanService;
use Modules\Ausruestung\src\Services\LoanService;
use Modules\Ausruestung\src\Services\RecallService;
use Modules\Ausruestung\src\Services\ReminderService;
use Modules\Ausruestung\src\Support\PublicScan;

class AusruestungServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([AusruestungMailer::class, EquipmentService::class, LoanService::class, RecallService::class, ReminderService::class, LoadPlanService::class, InventoryExport::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'ausruestung');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendAusruestungReminders::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // Daily digest for the Gerätewarte and reminders for overdue loans
            $schedule->command(SendAusruestungReminders::class)->dailyAt('07:00')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Livewire::component('ausruestung.equipment-list', EquipmentList::class);
        Livewire::component('ausruestung.equipment-detail', EquipmentDetail::class);
        Livewire::component('ausruestung.my-loans', MyLoans::class);

        app(ModuleRegistry::class)->register(
            'ausruestung',
            'Ausrüstungsverwaltung',
            'Geräte, Prüffristen, Ausleihe, Rückrufe und Beladeplan',
            roles: ['geraetewart'],
            provider: self::class,
            route: 'ausruestung.index',
        );

        // The own label code or the manufacturer's barcode: Gerätewarte get the item in the
        // admin area, everyone else its page in the app
        app(ScanRegistry::class)->register('ausruestung', function (string $code): ?string {
            $equipment = Equipment::where('code', $code)->first()
                ?? Equipment::where('barcode', $code)->first();

            if ($equipment === null) {
                return null;
            }

            return match (true) {
                (bool) auth()->user()?->canManageAusruestung() => EquipmentResource::getUrl('edit', ['record' => $equipment], panel: 'ausruestung'),
                // Not logged in: the short Geräteinfo, if a Gerätewart switched it on
                ! auth()->check() && PublicScan::enabled() => route('ausruestung.public', $equipment->code),
                default => route('ausruestung.show', $equipment),
            };
        });
    }
}
