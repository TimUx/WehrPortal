<?php

namespace Modules\Schluessel;

use App\Models\User;
use App\Services\DashboardRegistry;
use App\Services\InventoryRegistry;
use App\Services\ModuleRegistry;
use App\Services\PersonnelFileRegistry;
use App\Services\ScanRegistry;
use App\Support\ModuleNav;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Modules\Schluessel\src\Console\Commands\SendKeyReminders;
use Modules\Schluessel\src\Livewire\KeyDetail;
use Modules\Schluessel\src\Livewire\KeyForm;
use Modules\Schluessel\src\Livewire\KeyList;
use Modules\Schluessel\src\Livewire\MyKeys;
use Modules\Schluessel\src\Models\Handover;
use Modules\Schluessel\src\Models\Key;
use Modules\Schluessel\src\Services\KeyService;

/**
 * Schlüsselverwaltung: keys, transponders and cards with what they open (shared places,
 * vehicles, Objektpläne), handed out with a signed Schlüsselquittung; Kameraden see their own keys.
 */
class SchluesselServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyService::class);
    }

    public function boot(): void
    {
        $base = __DIR__;

        $this->loadViewsFrom($base.'/resources/views', 'schluessel');
        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($base.'/routes/web.php');
        }
        $this->loadMigrationsFrom($base.'/database/migrations');

        $this->commands([SendKeyReminders::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');

            $schedule->command(SendKeyReminders::class)->dailyAt('07:10')->timezone('Europe/Berlin')->withoutOverlapping()->appendOutputTo($output);
        });

        Gate::define('schluessel-manage', fn (User $user) => KeyService::canManage($user));

        foreach (['my-keys' => MyKeys::class, 'key-list' => KeyList::class, 'key-detail' => KeyDetail::class, 'key-form' => KeyForm::class] as $name => $class) {
            Livewire::component('schluessel.'.$name, $class);
        }

        app(ModuleRegistry::class)->register(
            'schluessel',
            'Schlüsselverwaltung',
            'Schlüssel, Transponder und Karten mit Schlüsselquittung, wer welchen hat, Verlust und Rückgabe',
            roles: ['schluesselwart'],
            provider: self::class,
            route: 'schluessel.index',
            // Kameraden without the role find their keys on the Profil
            startRoute: fn (User $user) => KeyService::canManage($user) ? 'schluessel.index' : 'profile',
            // The Schlüsselwart need not be an admin: "Admin" leads to all keys
        );

        // The own keys right on the Profil; the module itself is the list of all keys for the Schlüsselwart
        app(PersonnelFileRegistry::class)->section('schluessel', null, 'schluessel.my-keys');

        // The label of a key: the Schlüsselwart get the key, everyone else their own keys
        app(ScanRegistry::class)->register('schluessel', function (string $code): ?string {
            $key = Key::where('code', $code)->first();
            if (! $key) {
                return null;
            }

            return auth()->user() && KeyService::canManage(auth()->user()) ? route('schluessel.show', $key) : route('profile').'#schluessel';
        });

        // Bestand: keys by code or name, for the Schlüsselwart
        app(InventoryRegistry::class)->register('schluessel', 'Schlüssel', function (string $term, User $user) {
            if (! KeyService::canManage($user)) {
                return null;
            }
            $like = '%'.$term.'%';

            return Key::with('current.user')->where(fn ($q) => $q->where('code', 'ilike', $like)->orWhere('name', 'ilike', $like)->orWhere('locking', 'ilike', $like))
                ->orderBy('code')->limit(50)->get()
                ->map(fn (Key $key) => ['title' => $key->name, 'code' => $key->code, 'place' => $key->current?->user?->name ?? $key->storage,
                    'state' => $key->statusLabel(), 'tone' => match (true) {
                        $key->status === 'lost' => 'red', $key->status === 'retired' => 'gray', (bool) $key->current => 'blue', default => 'green'
                    },
                    'url' => route('schluessel.show', $key)]);
        }, order: 50);

        // Start page: keys past their return date (own), and for the Schlüsselwart lost ones and keys at former members
        app(DashboardRegistry::class)->register('schluessel', 'status', function (User $user) {
            $manage = KeyService::canManage($user);
            $lines = [];
            $own = Handover::where('user_id', $user->id)->whereNull('returned_at')->whereNotNull('due_on')->where('due_on', '<', KeyService::today()->toDateString())->count();
            if ($own) {
                $lines[] = $own.' Schlüssel zurückgeben – Frist abgelaufen';
            }
            if ($manage) {
                $overdue = Handover::whereNull('returned_at')->whereNotNull('due_on')->where('due_on', '<', KeyService::today()->toDateString())->count();
                $former = app(KeyService::class)->atFormerMembers()->count();
                $lost = Key::where('status', 'lost')->count();
                $lines = [...$lines, ...array_filter([
                    $overdue ? $overdue.' Schlüssel überfällig' : null,
                    $former ? $former.' Schlüssel bei nicht mehr aktiven Kameraden' : null,
                    $lost ? $lost.' Schlüssel verloren' : null,
                ])];
            }

            return $lines === [] ? null : view('schluessel::cards.status', compact('lines', 'manage'))->render();
        });

        // The Modulleiste: tabs and Verwaltung, each only for who may use it
        app(ModuleRegistry::class)->navigation('schluessel', fn (User $u) => [
            'tabs' => [
                KeyService::canManage($u) ? ModuleNav::tab('Alle Schlüssel', 'schluessel.index', ['schluessel.show', 'schluessel.edit', 'schluessel.create']) : null,
            ],
        ]);
    }
}
