<?php

namespace Modules\Gefahrenmatrix;

use App\Services\ModuleRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Gefahrenmatrix\src\Livewire\Matrix;

class GefahrenmatrixServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'gefahrenmatrix');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Livewire::component('gefahrenmatrix.matrix', Matrix::class);

        app(ModuleRegistry::class)->register(
            'gefahrenmatrix',
            'Gefahrenmatrix',
            'Gefahren an der Einsatzstelle strukturiert erkennen',
            provider: self::class,
            route: 'gefahrenmatrix.index',
        );
    }
}
