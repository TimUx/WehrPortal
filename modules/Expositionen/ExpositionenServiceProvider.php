<?php

namespace Modules\Expositionen;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Expositionen\src\Livewire\CrewForm;
use Modules\Expositionen\src\Livewire\ExposureForm;
use Modules\Expositionen\src\Livewire\Manage;
use Modules\Expositionen\src\Livewire\MyExposures;
use Modules\Expositionen\src\Models\Exposure;
use Modules\Expositionen\src\Services\ExposureService;

class ExpositionenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ExposureService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'expositionen');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('expositionen-manage', fn (User $user) => ExposureService::canManage($user));

        foreach (['my-exposures' => MyExposures::class, 'exposure-form' => ExposureForm::class, 'crew-form' => CrewForm::class, 'manage' => Manage::class] as $name => $class) {
            Livewire::component('expositionen.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'expositionen',
            'Expositionen (Gefahrstoffe)',
            'Gefahrstoff-Expositionen nach Einsatz und Übung erfassen, 40 Jahre aufbewahren, Meldeliste für die DGUV-ZED',
            roles: ['expositionsbeauftragter'],
            provider: self::class,
            route: 'expositionen.index',
        );

        app(PersonnelFileRegistry::class)->register('expositionen', 'Expositionen', 'expositionen.personnel-file');

        // Start page: proposals to confirm, recent Brand-Einsätze to answer, and for the Beauftragte what isn't reported
        app(DashboardRegistry::class)->register('expositionen', 'attention', function (User $user) {
            $drafts = Exposure::where('user_id', $user->id)->where('status', 'draft')->count();
            $prompts = app(ExposureService::class)->openPrompts($user)->count();
            $manage = ExposureService::canManage($user) ? ExposureService::managedExposures($user)->where('status', 'recorded')->count() : 0;

            return $drafts + $prompts + $manage === 0 ? null : view('expositionen::cards.attention', compact('drafts', 'prompts', 'manage'))->render();
        });

        // Jahresbericht: exposures of the year
        app(AnnualReportRegistry::class)->register('expositionen', 'Gefahrstoff-Expositionen', function (int $year) {
            $user = auth()->user();
            if (! $user || ! ExposureService::canManage($user)) {
                return null;
            }
            $rows = ExposureService::managedExposures($user)->where('status', '!=', 'draft')
                ->whereYear('occurred_on', $year)->get(['user_id', 'person_name', 'status']);

            return $rows->isEmpty() ? null : view('expositionen::annual.section', [
                'total' => $rows->count(), 'people' => $rows->pluck('person_name')->unique()->count(), 'reported' => $rows->where('status', 'reported')->count(),
            ])->render();
        }, 45);

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('expositionen', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Meine Expositionen', 'expositionen.index', ['expositionen.edit', 'expositionen.crew']),
                ExposureService::canManage($u) ? ModuleNav::tab('Meldeliste', 'expositionen.manage') : null,
            ],
        ]);
    }
}
