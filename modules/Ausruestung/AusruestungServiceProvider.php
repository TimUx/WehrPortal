<?php

namespace Modules\Ausruestung;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Ausruestung\src\Console\Commands\SendAusruestungReminders;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Livewire\EquipmentDetail;
use Modules\Ausruestung\src\Livewire\EquipmentList;
use Modules\Ausruestung\src\Livewire\MyLoans;
use Modules\Ausruestung\src\Livewire\StocktakeForm;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Inspection;
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
        Livewire::component('ausruestung.stocktake-form', StocktakeForm::class);

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
            // Inventarnummer, Hersteller-Barcode or RFID/NFC tag
            $equipment = Equipment::findByScan($code);

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

        // Jahresbericht: inspections of the equipment
        app(AnnualReportRegistry::class)->register('ausruestung', 'Geräteprüfungen', function (int $year) {
            $done = Inspection::whereYear('inspected_at', $year);
            $total = (clone $done)->count();

            return $total === 0 ? null : '<div class="ar-grid"><div class="ar-tile"><span>Prüfungen</span><strong>'.$total.'</strong></div>'
                .'<div class="ar-tile"><span>davon nicht bestanden</span><strong>'.(clone $done)->where('passed', false)->count().'</strong></div></div>';
        }, 70);

        // Bestand: Geräte by name, code, serial number, barcode or tag; Ersatzbeschaffung from the Lebensdauer
        app(InventoryRegistry::class)->register('ausruestung', 'Geräte', function (string $term, User $user) {
            if (! $user->canUseModule('ausruestung')) {
                return null;
            }
            $like = '%'.$term.'%';
            $tag = Equipment::normalizeTag($term);

            return Equipment::with(['category', 'location', 'compartment.location'])->inService()
                ->where(fn ($q) => $q->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)->orWhere('serial_number', 'ilike', $like)
                    ->orWhere('barcode', $term)->orWhere('manufacturer', 'ilike', $like)->orWhere('model', 'ilike', $like)
                    ->when($tag, fn ($q) => $q->orWhere('rfid', $tag)))
                ->orderBy('name')->limit(50)->get()
                ->map(fn (Equipment $e) => ['title' => $e->name, 'code' => $e->code, 'place' => $e->placeLabel(),
                    'state' => $e->warnings()[0] ?? $e->status->label(), 'tone' => $e->isUsable() ? 'green' : ($e->status->value === 'loaned' ? 'blue' : 'red'),
                    'url' => route('ausruestung.show', $e)]);
        }, function (User $user, int $from, int $to) {
            if (! $user->canManageAusruestung()) {
                return null;
            }

            return Equipment::with('category')->inService()->get()
                ->filter(fn (Equipment $e) => $e->lifetimeEndsAt() && $e->lifetimeEndsAt()->year <= $to)
                ->map(fn (Equipment $e) => ['year' => $e->lifetimeEndsAt()->year, 'title' => $e->name.' ('.$e->code.')',
                    'detail' => 'Lebensdauer bis '.$e->lifetimeEndsAt()->format('d.m.Y').($e->category ? ' · '.$e->category->name : ''),
                    'cost_cents' => $e->purchase_price !== null ? (int) round((float) $e->purchase_price * 100) : null, 'url' => route('ausruestung.show', $e)]);
        }, 20);

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('ausruestung', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Geräte', 'ausruestung.equipment', ['ausruestung.show']),
                ModuleNav::tab('Meine Ausleihen', 'ausruestung.index'),
                $u->canManageAusruestung() ? ModuleNav::tab('Inventur', 'ausruestung.stocktake') : null,
            ],
            'manage' => $u->canManageAusruestung() ? route('filament.ausruestung.pages.dashboard') : null,
        ]);
    }
}
