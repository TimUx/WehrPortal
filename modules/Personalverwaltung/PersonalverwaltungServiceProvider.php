<?php

namespace Modules\Personalverwaltung;

use App\Livewire\Profile\Hours;
use App\Models\User;
use App\Services\AnnualReportRegistry;
use App\Services\CredentialRegistry;
use App\Services\DashboardRegistry;
use App\Services\HoursRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Console\Commands\SyncHistory;
use Modules\Personalverwaltung\src\Livewire\Access;
use Modules\Personalverwaltung\src\Livewire\Attendance;
use Modules\Personalverwaltung\src\Livewire\ChangeRequests;
use Modules\Personalverwaltung\src\Livewire\DigitalId;
use Modules\Personalverwaltung\src\Livewire\Honors;
use Modules\Personalverwaltung\src\Livewire\HoursOverview;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Livewire\NewMembers;
use Modules\Personalverwaltung\src\Livewire\OwnData;
use Modules\Personalverwaltung\src\Livewire\OwnFile;
use Modules\Personalverwaltung\src\Livewire\Reports;
use Modules\Personalverwaltung\src\Livewire\Settings;
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
        Gate::define('personalverwaltung-view', fn (User $user) => PersonnelService::canView($user));

        foreach (['member-list' => MemberList::class, 'member-file' => MemberFile::class, 'honors' => Honors::class,
            'attendance' => Attendance::class, 'access' => Access::class, 'own-file' => OwnFile::class,
            'settings' => Settings::class, 'own-data' => OwnData::class, 'change-requests' => ChangeRequests::class, 'hours-overview' => HoursOverview::class, 'reports' => Reports::class, 'new-members' => NewMembers::class,
            'digital-id' => DigitalId::class] as $name => $class) {
            Livewire::component('personalverwaltung.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'personalverwaltung',
            'Personalverwaltung',
            'Persönliche Daten, Laufbahn mit Dienstgraden, Abteilungen und Funktionen, Ehrungen, Dienstausweis und Dienstbeteiligung je Kamerad',
            roles: ['personalverwalter', 'personaleinsicht'],
            provider: self::class,
            route: 'personalverwaltung.own',
            // Its own admin area for the Personalverwalter, who need not be admins
        );

        app(PersonnelFileRegistry::class)->register('personalverwaltung', 'Laufbahn & Ehrungen', 'personalverwaltung.own');
        app(PersonnelFileRegistry::class)->register('personalverwaltung', 'Persönliche Daten', 'personalverwaltung.own-data', fn () => PersonnelService::selfService());

        // Personalverwalter: open Änderungswünsche, Ehrungen due this year and Dienstausweise running out
        app(DashboardRegistry::class)->register('personalverwaltung', 'due', function (User $user) {
            if (! PersonnelService::canManage($user)) {
                return null;
            }
            $requests = app(PersonnelService::class)->openRequests($user)->count();
            $due = app(PersonnelService::class)->dueHonors($user, (int) now('Europe/Berlin')->year)->count();
            $cards = MemberProfile::whereIn('user_id', PersonnelService::visibleMembers($user)->where('status', 'active')->select('id'))
                ->whereNotNull('id_card_valid_until')->where('id_card_valid_until', '<=', PersonnelService::today()->addDays(60)->toDateString())->count();

            return $requests + $due + $cards === 0 ? null : view('personalverwaltung::cards.due', compact('requests', 'due', 'cards'))->render();
        });

        // Personalakte → Nachweise: the Dienstausweis on the phone, while the Personalverwalter switched it on
        // The Dienstausweis has its own page (/dienstausweis, the card icon in the header), not a card
        // under Nachweise; the checker's page after the QR scan still shows it
        app(CredentialRegistry::class)->register('personalverwaltung', 'Dienstausweis',
            fn (User $user) => null,
            fn (User $user) => ($id = PersonnelService::digitalIdFor($user)) ? view('personalverwaltung::credentials.id-check', ['id' => $id])->render() : null,
        );

        // Jahresbericht: the hours of all active Kameraden by source
        app(AnnualReportRegistry::class)->register('personalverwaltung', 'Geleistete Stunden', function (int $year) {
            $rows = app(HoursRegistry::class)->rows(User::active()->pluck('id')->all(), ...Hours::period($year));

            return $rows->isEmpty() ? null : view('personalverwaltung::annual.hours', [
                'bySource' => $rows->groupBy('source')->map->sum('minutes'), 'total' => $rows->sum('minutes'), 'people' => $rows->pluck('user_id')->unique()->count(),
            ])->render();
        }, 22);

        // Jahresbericht: strength, Eintritte, Austritte, Beförderungen, Ehrungen
        app(AnnualReportRegistry::class)->register('personalverwaltung', 'Mitglieder', fn (int $year) => view('personalverwaltung::annual.section', [
            'a' => app(PersonnelService::class)->annual($year),
        ])->render(), 20);

        // The Modulleiste: tabs, main action and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('personalverwaltung', fn (User $u) => [
            'tabs' => [
                ModuleNav::tab('Kameraden', 'personalverwaltung.index', ['personalverwaltung.member']),
                PersonnelService::canManage($u) ? ModuleNav::tab('Neue Mitglieder', 'personalverwaltung.new') : null,
                PersonnelService::canManage($u) ? ModuleNav::tab('Änderungswünsche', 'personalverwaltung.requests') : null,
                PersonnelService::canManage($u) ? ModuleNav::tab('Ehrungen', 'personalverwaltung.honors') : null,
                ModuleNav::tab('Auswertungen', 'personalverwaltung.reports'),
                app(HoursRegistry::class)->sources() ? ModuleNav::tab('Stunden', 'personalverwaltung.hours') : null,
                PersonnelService::attendanceAvailable() ? ModuleNav::tab('Dienstbeteiligung', 'personalverwaltung.attendance') : null,
                PersonnelService::canManage($u) ? ModuleNav::tab('Einstellungen', 'personalverwaltung.settings') : null,
                $u->is_admin ? ModuleNav::tab('Zugriff', 'personalverwaltung.access') : null,
            ],
        ]);
    }
}
