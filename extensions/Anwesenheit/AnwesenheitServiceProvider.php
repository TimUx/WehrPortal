<?php

namespace Extensions\Anwesenheit;

use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\HoursRegistry;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Carbon\Carbon;
use Extensions\Anwesenheit\Filament\AnwesenheitPlugin;
use Extensions\Anwesenheit\Livewire\ListForm;
use Extensions\Anwesenheit\Livewire\ListIndex;
use Extensions\Anwesenheit\Livewire\ListShow;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Models\AttendanceTemplate;
use Extensions\Anwesenheit\Services\AttendanceService;
use Extensions\Locations\Scopes\LocationScope;
use Extensions\Locations\Support\AssignsLocation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Standalone Anwesenheitslisten (Übungen, Dienste …): leader, participants, automatic duration and count,
 * PDF and mail with uploads, offline drafts through the Offline extension. A list never needs a Kalender
 * event. Switched off with ANWESENHEIT_ENABLED=false.
 */
class AnwesenheitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/anwesenheit.php', 'anwesenheit');
        $this->app->singleton(AttendanceService::class);

        $this->app->booting(function () {
            if (config('anwesenheit.enabled')) {
                Filament::getPanel('admin')?->plugin(AnwesenheitPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        if (! config('anwesenheit.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'anwesenheit');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'anwesenheit');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        // Standort boundaries: the same scope and hooks the Locations extension puts on the core models
        if (config('locations.enabled')) {
            foreach ([AttendanceList::class, AttendanceTemplate::class] as $class) {
                $class::addGlobalScope(new LocationScope);
                $class::creating(fn ($model) => AssignsLocation::creating($model));
                $class::updating(fn ($model) => AssignsLocation::updating($model));
            }
        }

        foreach (['list-index' => ListIndex::class, 'list-form' => ListForm::class, 'list-show' => ListShow::class] as $name => $class) {
            Livewire::component('anwesenheit.'.$name, $class);
        }

        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register(
            'anwesenheit',
            'Anwesenheitslisten',
            'Eigenständige Anwesenheitslisten für Übungen und Dienste: Leitung, Teilnehmer, Dauer, PDF und E-Mail – auch ohne Kalendertermin',
            roles: ['verwalter'],
            provider: self::class,
            route: 'anwesenheit.index',
        );
        $registry->navigation('anwesenheit', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Listen', 'anwesenheit.index', ['anwesenheit.show', 'anwesenheit.edit']),
                ModuleNav::tab('Neue Liste', 'anwesenheit.create'),
            ],
            'manage' => AttendanceService::canManage($u) ? url('/admin/anwesenheit/einstellungen') : null,
        ]);

        // Stundennachweis: submitted lists, only the persons who were there (nothing is stored twice)
        $this->app->make(HoursRegistry::class)->register('anwesenheit', 'Anwesenheitslisten', fn (array $ids, Carbon $from, Carbon $to) => AttendanceList::query()
            ->where('status', AttendanceList::SUBMITTED)->whereBetween('starts_at', [$from, $to])
            ->whereHas('participants', fn ($q) => $q->whereIn('users.id', $ids))
            ->with(['participants' => fn ($q) => $q->whereIn('users.id', $ids)])->get()
            ->flatMap(fn (AttendanceList $l) => $l->participants->map(fn (User $p) => ['user_id' => $p->id, 'date' => $l->starts_at, 'title' => $l->title, 'minutes' => $l->duration_minutes])));

        $this->app->make(AnnualReportRegistry::class)->register('anwesenheit', 'Anwesenheitslisten', function (int $year) {
            $lists = AttendanceList::where('status', AttendanceList::SUBMITTED)->whereYear('starts_at', $year);
            $count = (clone $lists)->count();
            if ($count === 0) {
                return null;
            }
            $hours = round(((int) (clone $lists)->sum('duration_minutes')) / 60, 1);
            $persons = (int) (clone $lists)->sum('participant_count');
            $unique = DB::table('x_att_participants')->whereIn('list_id', (clone $lists)->select('id'))->distinct()->count('user_id');

            return '<div class="ar-grid"><div class="ar-tile"><span>Anwesenheitslisten</span><strong>'.$count.'</strong></div>'
                .'<div class="ar-tile"><span>Stunden</span><strong>'.number_format($hours, 1, ',', '.').'</strong></div>'
                .'<div class="ar-tile"><span>Teilnahmen</span><strong>'.$persons.'</strong></div>'
                .'<div class="ar-tile"><span>Verschiedene Kameraden</span><strong>'.$unique.'</strong></div></div>';
        }, 80);
    }
}
