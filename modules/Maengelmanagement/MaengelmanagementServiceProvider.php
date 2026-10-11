<?php

namespace Modules\Maengelmanagement;

use App\Services\ModuleRegistry;
use App\Services\ScanRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Maengelmanagement\src\Livewire\DefectDetail;
use Modules\Maengelmanagement\src\Livewire\DefectList;
use Modules\Maengelmanagement\src\Livewire\ReportForm;
use Modules\Maengelmanagement\src\Models\Device;
use Modules\Maengelmanagement\src\Services\DefectPhotoService;
use Modules\Maengelmanagement\src\Services\DefectService;

class MaengelmanagementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DefectPhotoService::class);
        $this->app->singleton(DefectService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'maengelmanagement');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Livewire::component('maengelmanagement.defect-list', DefectList::class);
        Livewire::component('maengelmanagement.report-form', ReportForm::class);
        Livewire::component('maengelmanagement.defect-detail', DefectDetail::class);

        app(ModuleRegistry::class)->register(
            'maengelmanagement',
            'Mängelmanagement',
            'Mängel per QR-Code-Scan melden, bearbeiten und bis zur Erledigung verfolgen',
            roles: ['verantwortlicher'],
            provider: self::class,
            route: 'maengelmanagement.index',
        );

        // A scan of a device label opens the report form with that device preselected
        app(ScanRegistry::class)->register('maengelmanagement', function (string $code): ?string {
            return Device::active()->where('code', $code)->exists()
                ? route('maengelmanagement.report', ['objekt' => $code])
                : null;
        });
    }
}
