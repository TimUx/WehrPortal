<?php

namespace Modules\Finanzen;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Finanzen\src\Livewire\BudgetPlan;
use Modules\Finanzen\src\Livewire\EntryDetail;
use Modules\Finanzen\src\Livewire\EntryForm;
use Modules\Finanzen\src\Livewire\EntryList;
use Modules\Finanzen\src\Livewire\Overview;
use Modules\Finanzen\src\Livewire\YearReport;
use Modules\Finanzen\src\Models\Entry;
use Modules\Finanzen\src\Services\FinanceExport;
use Modules\Finanzen\src\Services\FinanceService;

/**
 * Budget & Finanzen: budgets per Kostenstelle and year, Ausgaben with Beleg and approval, Einnahmen,
 * Jahresabschluss and export. Everyone with access sees the budget status; Kassenwart and
 * Wehrführung approve, the Kassenwart books and pays.
 */
class FinanzenServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FinanceService::class);
        $this->app->singleton(FinanceExport::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'finanzen');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        Gate::define('finanzen-manage', fn (User $user) => FinanceService::canManage($user));
        Gate::define('finanzen-review', fn (User $user) => FinanceService::canReview($user));

        foreach (['overview' => Overview::class, 'entry-form' => EntryForm::class, 'entry-detail' => EntryDetail::class, 'entry-list' => EntryList::class,
            'budget-plan' => BudgetPlan::class, 'year-report' => YearReport::class] as $name => $class) {
            Livewire::component('finanzen.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'finanzen',
            'Budget & Finanzen',
            'Budgets, Ausgaben mit Beleg und Genehmigung, Jahresabschluss – transparent für die ganze Mannschaft',
            roles: ['kassenwart', 'wehrführung'],
            provider: self::class,
            route: 'finanzen.index',
        );

        // Start page: what waits for the reviewers, the Kassenwart and the own Ausgaben
        app(DashboardRegistry::class)->register('finanzen', 'todo', function (User $user) {
            $todo = [
                'review' => FinanceService::canReview($user) ? Entry::where('status', 'submitted')->where('submitted_by', '!=', $user->id)->count() : 0,
                'pay' => FinanceService::canManage($user) ? Entry::where('status', 'approved')->count() : 0,
                'own' => Entry::where('submitted_by', $user->id)->whereIn('status', ['submitted', 'approved'])->count(),
            ];

            return array_sum($todo) > 0 ? view('finanzen::cards.todo', ['todo' => $todo])->render() : null;
        });

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('finanzen', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Übersicht', 'finanzen.index', ['finanzen.entry', 'finanzen.edit']),
                FinanceService::canReview($u) ? ModuleNav::tab('Buchungen', 'finanzen.entries', ['finanzen.book']) : null,
                FinanceService::canManage($u) ? ModuleNav::tab('Budget & Kostenstellen', 'finanzen.budget') : null,
                FinanceService::canReview($u) ? ModuleNav::tab('Jahresabschluss', 'finanzen.report') : null,
            ],
            'action' => ModuleNav::action('Ausgabe einreichen', 'finanzen.submit'),
        ]);
    }
}
