<?php

namespace App\Providers;

use App\Services\ActivityRecorder;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\OrganizationSettings;
use App\Services\PersonnelFileRegistry;
use App\Services\ScanRegistry;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(OrganizationSettings::class);
        $this->app->singleton(ScanRegistry::class);
        $this->app->singleton(DashboardRegistry::class);
        $this->app->singleton(ActivityRecorder::class);
        $this->app->singleton(PersonnelFileRegistry::class);
    }

    public function boot(): void
    {
        // Every change of a record goes to the admin "Logs"
        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", fn (string $name, array $payload) => app(ActivityRecorder::class)->handle($name, $payload));
        }

        FilamentView::registerRenderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.brand-buttons'));
        // A Kamerad's module panels (e.g. Kleiderwart) also show the way back for an admin viewing as them
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_START, fn () => view('partials.impersonation-banner'));

        // Share org settings with every view under the app layout
        View::composer(['components.layouts.app', 'filament.*'], function ($view) {
            $org = app(OrganizationSettings::class);
            $view->with('orgName', $org->shortName());
            $view->with('orgLogoUrl', $org->logoUrl() ?? asset('images/openwehr-logo.svg'));
        });
    }
}
