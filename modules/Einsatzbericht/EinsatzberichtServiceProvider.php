<?php

namespace Modules\Einsatzbericht;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ExternalReport;
use Modules\Einsatzbericht\src\Livewire\MaterialCatalog;
use Modules\Einsatzbericht\src\Livewire\MemberMissions;
use Modules\Einsatzbericht\src\Livewire\Members;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Livewire\ReportList;
use Modules\Einsatzbericht\src\Livewire\Settings;
use Modules\Einsatzbericht\src\Livewire\SituationLog;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\DiveraWebhook;
use Modules\Einsatzbericht\src\Services\ExternalAccess;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Services\SituationService;

/**
 * Einsatzbericht: the Einsatzleiter records each Einsatz once, the Wehrführung releases it. The
 * same data gives the Jahresstatistik and the Einsätze in the Personalakte of every Kamerad.
 */
class EinsatzberichtServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReportService::class);
        $this->app->singleton(DiveraWebhook::class);
        $this->app->singleton(SituationService::class);
        $this->app->singleton(ExternalAccess::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'einsatzbericht');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
            Route::group([], $base.'/routes/webhooks.php');
        }

        // DIVERA: at most 30 requests a minute per address
        RateLimiter::for('divera', fn (Request $request) => Limit::perMinute(30)->by('divera|'.$request->route('key')));
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('einsatzbericht-lead', fn (User $user) => ReportService::canLead($user));
        Gate::define('einsatzbericht-release', fn (User $user) => ReportService::canRelease($user));
        Gate::define('einsatzbericht-materials', fn (User $user) => $user->is_admin && $user->is_active);

        foreach (['report-list' => ReportList::class, 'report-editor' => ReportEditor::class, 'statistics' => Statistics::class,
            'settings' => Settings::class, 'members' => Members::class, 'member-missions' => MemberMissions::class,
            'situation-log' => SituationLog::class, 'material-catalog' => MaterialCatalog::class, 'external-report' => ExternalReport::class] as $name => $class) {
            Livewire::component('einsatzbericht.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'einsatzbericht',
            'Einsatzbericht',
            'Einsätze digital erfassen und freigeben, Besatzung und Stärke, Jahresstatistik und Einsätze in der Personalakte',
            roles: ['einsatzleiter', 'wehrführung'],
            provider: self::class,
            route: 'einsatzbericht.index',
        );

        app(PersonnelFileRegistry::class)->register('einsatzbericht', 'Einsätze', 'einsatzbericht.personnel-file');

        // Start page: drafts and current Einsätze for the Einsatzleiter, reports to release for the Wehrführung
        app(DashboardRegistry::class)->register('einsatzbericht', 'open', function (User $user) {
            $lead = ReportService::canLead($user);
            $drafts = $lead ? Report::where('status', 'draft')->count() : 0;
            $active = $lead ? Report::where('status', 'active')->count() : 0;
            $toRelease = ReportService::canRelease($user) ? Report::where('status', 'closed')->count() : 0;

            return $drafts + $active + $toRelease === 0 ? null : view('einsatzbericht::cards.open', compact('drafts', 'active', 'toRelease'))->render();
        });

        // Jahresbericht: the Einsätze come first
        app(AnnualReportRegistry::class)->register('einsatzbericht', 'Einsätze', fn (int $year) => view('einsatzbericht::annual.section', [
            'stats' => app(ReportService::class)->statistics($year),
        ])->render(), 10);
    }
}
