<?php

namespace Modules\Verbandbuch;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Verbandbuch\src\Livewire\EntryDetail;
use Modules\Verbandbuch\src\Livewire\EntryForm;
use Modules\Verbandbuch\src\Livewire\EntryList;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

/**
 * Verbandbuch & Vorfälle: injuries, accidents and incidents in the Dienst, documented after
 * DGUV Information 204-021 and signed on the screen. Every Kamerad with access writes entries;
 * Sicherheitsbeauftragter and Wehrführung see all of them. Health data stays out of mails and the
 * activity log and is deleted after the retention time.
 */
class VerbandbuchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VerbandbuchService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'verbandbuch');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('verbandbuch-manage', fn (User $user) => VerbandbuchService::canManage($user));

        foreach (['entry-form' => EntryForm::class, 'entry-detail' => EntryDetail::class, 'entry-list' => EntryList::class] as $name => $class) {
            Livewire::component('verbandbuch.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'verbandbuch',
            'Verbandbuch & Vorfälle',
            'Verbandbuch nach DGUV rechtssicher digital führen – Verletzungen und Vorfälle mit Unterschrift dokumentieren',
            roles: VerbandbuchService::MANAGER_ROLES,
            provider: self::class,
            route: 'verbandbuch.index',
        );

        app(PersonnelFileRegistry::class)->register('verbandbuch', 'Verbandbuch', 'verbandbuch.personnel-file');

        // Start page: accidents with a doctor's visit that still need the Unfallanzeige
        app(DashboardRegistry::class)->register('verbandbuch', 'report', function (User $user) {
            $count = VerbandbuchService::canManage($user) ? Entry::awaitingReport()->count() : 0;

            return $count === 0 ? null : view('verbandbuch::cards.report', compact('count'))->render();
        });
    }
}
