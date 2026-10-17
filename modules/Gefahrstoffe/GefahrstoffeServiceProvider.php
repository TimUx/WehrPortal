<?php

namespace Modules\Gefahrstoffe;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Gefahrstoffe\src\Livewire\ImportCatalog;

class GefahrstoffeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'gefahrstoffe');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('gefahrstoffe-manage', fn (User $user) => $user->is_active
            && $user->canUseModule('gefahrstoffe')
            && ($user->is_admin || $user->hasModuleRole('gefahrstoffe', 'gefahrstoffbeauftragter')));

        Livewire::component('gefahrstoffe.import-catalog', ImportCatalog::class);

        app(ModuleRegistry::class)->register(
            'gefahrstoffe',
            'Gefahrstoffdatenbank',
            'UN-Nummern und Gefahrgutdaten durchsuchen und eigene Datenbestände importieren',
            roles: ['gefahrstoffbeauftragter'],
            provider: self::class,
            route: 'gefahrstoffe.index',
        );

        app(ModuleRegistry::class)->navigation('gefahrstoffe', fn (User $user) => [
            'tabs' => [
                ModuleNav::tab('Datenbank', 'gefahrstoffe.index'),
                $user->can('gefahrstoffe-manage') ? ModuleNav::tab('Import', 'gefahrstoffe.import') : null,
            ],
        ]);
    }
}
