<?php

namespace Extensions\Offline;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OfflineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/offline.php', 'offline');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/resources/views', 'offline');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, fn () => config('offline.enabled') && auth()->check()
            ? view('offline::partials.status')
            : '');
    }
}
