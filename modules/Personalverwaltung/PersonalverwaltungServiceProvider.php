<?php

namespace Modules\Personalverwaltung;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Console\Commands\SyncHistory;
use Modules\Personalverwaltung\src\Livewire\Access;
use Modules\Personalverwaltung\src\Livewire\Attendance;
use Modules\Personalverwaltung\src\Livewire\Honors;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Livewire\OwnFile;
use Modules\Personalverwaltung\src\Models\MemberProfile;
use Modules\Personalverwaltung\src\Services\PersonnelService;
use Throwable;

/**
 * Personalverwaltung: the career of each Kamerad (Dienstgrade, Abteilungen, Funktionen, status),
 * Ehrungen with the ones due by Dienstjahre, the Dienstausweis and the Dienstbeteiligung from the
 * Kalender. Personalverwalter can be limited to Abteilungen.
 */
class PersonalverwaltungServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PersonnelService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'personalverwaltung');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SyncHistory::class]);

        // Changes that bypass the member form (imports, pivot syncs) reach the career within the hour
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SyncHistory::class)->hourly()->withoutOverlapping()->appendOutputTo($output);
        });

        // A saved Kamerad: once the request is done (Abteilungen and Funktionen are saved after the
        // record), his career is brought up to date
        $pending = [];
        User::saved(function (User $user) use (&$pending) {
            // A login or a new avatar changes nothing of the career
            $bookkeeping = ['last_login_at', 'remember_token', 'updated_at', 'avatar_updated_at', 'calendar_view'];
            if (isset($pending[$user->id]) || ($user->wasChanged() && array_diff(array_keys($user->getChanges()), $bookkeeping) === [])) {
                return;
            }
            $pending[$user->id] = true;
            $this->app->terminating(function () use ($user, &$pending) {
                unset($pending[$user->id]);
                try {
                    app(PersonnelService::class)->syncHistory($user->fresh() ?? $user);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });

        Gate::define('personalverwaltung-manage', fn (User $user) => PersonnelService::canManage($user));

        foreach (['member-list' => MemberList::class, 'member-file' => MemberFile::class, 'honors' => Honors::class,
            'attendance' => Attendance::class, 'access' => Access::class, 'own-file' => OwnFile::class] as $name => $class) {
            Livewire::component('personalverwaltung.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'personalverwaltung',
            'Personalverwaltung',
            'Laufbahn mit Dienstgraden, Abteilungen und Funktionen, Ehrungen, Dienstausweis und Dienstbeteiligung je Kamerad',
            roles: ['personalverwalter'],
            provider: self::class,
            route: 'personalverwaltung.own',
        );

        app(PersonnelFileRegistry::class)->register('personalverwaltung', 'Laufbahn & Ehrungen', 'personalverwaltung.own');

        // Personalverwalter: Ehrungen due this year and Dienstausweise running out
        app(DashboardRegistry::class)->register('personalverwaltung', 'due', function (User $user) {
            if (! PersonnelService::canManage($user)) {
                return null;
            }
            $due = app(PersonnelService::class)->dueHonors($user, (int) now('Europe/Berlin')->year)->count();
            $cards = MemberProfile::whereIn('user_id', PersonnelService::visibleMembers($user)->where('status', 'active')->select('id'))
                ->whereNotNull('id_card_valid_until')->where('id_card_valid_until', '<=', PersonnelService::today()->addDays(60)->toDateString())->count();

            return $due + $cards === 0 ? null : view('personalverwaltung::cards.due', compact('due', 'cards'))->render();
        });
    }
}
