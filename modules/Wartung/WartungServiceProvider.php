<?php

namespace Modules\Wartung;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Wartung\src\Console\Commands\RunWartungPlanner;
use Modules\Wartung\src\Livewire\DeviceDetail;
use Modules\Wartung\src\Livewire\Inspectors;
use Modules\Wartung\src\Livewire\Overview;
use Modules\Wartung\src\Livewire\ProtocolDetail;
use Modules\Wartung\src\Livewire\ProtocolForm;
use Modules\Wartung\src\Livewire\ProtocolList;
use Modules\Wartung\src\Livewire\Providers;
use Modules\Wartung\src\Livewire\TemplateForm;
use Modules\Wartung\src\Livewire\TemplateList;
use Modules\Wartung\src\Models\Task;
use Modules\Wartung\src\Services\WartungPdf;
use Modules\Wartung\src\Services\WartungService;

/**
 * Wartungsmanagement: Prüf- und Wartungsverwaltung for the devices of the Ausrüstungsverwaltung.
 * The Gerätewart keeps templates with interval and checklist, the module opens the due Wartungen,
 * Prüfer and Fachfirmen sign the protocol on the screen, the PDF goes to insurers and the DGUV.
 */
class WartungServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WartungService::class);
        $this->app->singleton(WartungPdf::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'wartung');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([RunWartungPlanner::class]);

        // Scheduled commands write to /dev/null by default, so send their output to the container log
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            // Open the due Wartungen and mail the Gerätewarte, before the Ausrüstung digest at 7
            $schedule->command(RunWartungPlanner::class)->dailyAt('06:45')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('wartung-manage', fn (User $user) => WartungService::canManage($user));
        Gate::define('wartung-perform', fn (User $user) => WartungService::canPerform($user));

        foreach (['overview' => Overview::class, 'protocol-form' => ProtocolForm::class, 'protocol-detail' => ProtocolDetail::class,
            'protocol-list' => ProtocolList::class, 'template-list' => TemplateList::class, 'template-form' => TemplateForm::class,
            'providers' => Providers::class, 'inspectors' => Inspectors::class, 'device-detail' => DeviceDetail::class] as $name => $class) {
            Livewire::component('wartung.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'wartung',
            'Wartungsmanagement',
            'Digitale Prüf- und Wartungsverwaltung mit Unterschrift für alle Feuerwehrgeräte und Fristen',
            roles: ['geraetewart', 'pruefer'],
            provider: self::class,
            route: 'wartung.index',
        );

        // Start page: overdue and due Wartungen for whoever carries them out
        app(DashboardRegistry::class)->register('wartung', 'due', function (User $user) {
            if (! WartungService::canPerform($user) || ! WartungService::ready()) {
                return null;
            }
            $overdue = Task::overdue()->count();
            $open = Task::open()->count();

            return $open === 0 ? null : view('wartung::cards.due', compact('overdue', 'open'))->render();
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('wartung', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Fällige Wartungen', 'wartung.index', ['wartung.perform', 'wartung.device']),
                ModuleNav::tab('Prüfprotokolle', 'wartung.protocols', ['wartung.protocol']),
                WartungService::canManage($u) ? ModuleNav::tab('Vorlagen', 'wartung.templates', ['wartung.template', 'wartung.template.create']) : null,
                WartungService::canManage($u) ? ModuleNav::tab('Fachfirmen', 'wartung.providers') : null,
                WartungService::canManage($u) ? ModuleNav::tab('Prüfer', 'wartung.inspectors') : null,
            ],
        ]);
    }
}
