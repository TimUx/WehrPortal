<?php

namespace Modules\Maengelmanagement;

use App\Models\User;
use App\Services\InventoryRegistry;
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

        // Bestand: the devices a defect can be reported for, with their open defects
        app(InventoryRegistry::class)->register('maengelmanagement', 'Mängelmanagement', function (string $term, User $user) {
            if (! $user->canUseModule('maengelmanagement')) {
                return null;
            }
            $like = '%'.$term.'%';

            return Device::active()->withCount(['defects' => fn ($q) => $q->active()])
                ->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('location', 'ilike', $like))
                ->orderBy('name')->limit(50)->get()
                ->map(fn (Device $d) => ['title' => $d->label(), 'code' => $d->code, 'place' => $d->location,
                    'state' => $d->defects_count ? $d->defects_count.' offene'.($d->defects_count === 1 ? 'r Mangel' : ' Mängel') : 'keine Mängel',
                    'tone' => $d->defects_count ? 'amber' : 'green', 'url' => route('maengelmanagement.report', ['objekt' => $d->code])]);
        }, order: 60);

        // A scan of a device label opens the report form with that device preselected
        app(ScanRegistry::class)->register('maengelmanagement', function (string $code): ?string {
            return Device::active()->where('code', $code)->exists()
                ? route('maengelmanagement.report', ['objekt' => $code])
                : null;
        });
    }
}
