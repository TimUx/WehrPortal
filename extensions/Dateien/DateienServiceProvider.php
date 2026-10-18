<?php

namespace Extensions\Dateien;

use App\Models\User;
use App\Services\ModuleRegistry;
use App\Support\ModuleNav;
use Extensions\Dateien\Console\DrainDeletionsCommand;
use Extensions\Dateien\Console\PruneAuditCommand;
use Extensions\Dateien\Console\PruneOfficeSessionsCommand;
use Extensions\Dateien\Console\PruneSharesCommand;
use Extensions\Dateien\Console\ReconcileCommand;
use Extensions\Dateien\Filament\DateienPlugin;
use Extensions\Dateien\Livewire\Explorer;
use Extensions\Dateien\Office\OfficeService;
use Extensions\Dateien\Office\OfficeSettings;
use Extensions\Dateien\Office\WopiDiscovery;
use Extensions\Dateien\Office\WopiProof;
use Extensions\Dateien\Services\FileAccess;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\GroupService;
use Extensions\Dateien\Services\Maintenance;
use Extensions\Dateien\Services\ShareService;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class DateienServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/dateien.php', 'dateien');
        config(['filesystems.disks.dateien' => [
            'driver' => 'local',
            'root' => storage_path('app/private/dateien'),
            'visibility' => 'private',
            'throw' => true,
        ]]);
        $this->app->singleton(FileAccess::class);
        $this->app->singleton(FileService::class);
        $this->app->singleton(GroupService::class);
        $this->app->singleton(Maintenance::class);
        $this->app->singleton(ShareService::class);
        $this->app->singleton(OfficeSettings::class);
        $this->app->singleton(OfficeService::class);
        $this->app->singleton(WopiDiscovery::class);
        $this->app->singleton(WopiProof::class);
        $this->app->booting(fn () => Filament::getPanel('admin')->plugin(DateienPlugin::make()));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'dateien');
        if (! $this->app->routesAreCached()) {
            Route::group([], __DIR__.'/routes/web.php');
        }
        Livewire::component('dateien.explorer', Explorer::class);

        foreach (['upload' => 'uploads', 'download' => 'downloads'] as $name => $key) {
            RateLimiter::for('dateien-'.$name, fn (Request $request) => Limit::perMinute(max(1, (int) config('dateien.rate_limits.'.$key.'_per_minute', 60)))
                ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
        // The office server calls from one address; keyed by IP because there is no session
        RateLimiter::for('dateien-office', fn (Request $request) => Limit::perMinute(max(1, (int) config('dateien.office.rate_limit', 600)))
            ->by('office|'.$request->ip()));
        RateLimiter::for('dateien-public', fn (Request $request) => Limit::perMinute(max(1, (int) config('dateien.shares.rate_limit', 60)))
            ->by('share|'.$request->ip()));
        if ($this->app->runningInConsole()) {
            $this->commands([ReconcileCommand::class, DrainDeletionsCommand::class, PruneAuditCommand::class, PruneOfficeSessionsCommand::class, PruneSharesCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $output = is_writable('/proc/1/fd/2') ? '/proc/1/fd/2' : storage_path('logs/schedule.log');
            $schedule->command('dateien:drain-deletions')->everyFifteenMinutes()->withoutOverlapping()->appendOutputTo($output);
            $schedule->command('dateien:prune-audit')->dailyAt('03:20')->withoutOverlapping()->appendOutputTo($output);
            $schedule->command('dateien:prune-office-sessions')->hourly()->withoutOverlapping()->appendOutputTo($output);
            $schedule->command('dateien:prune-shares')->dailyAt('03:30')->withoutOverlapping()->appendOutputTo($output);
            $schedule->command('dateien:reconcile')->weeklyOn(0, '03:40')->withoutOverlapping()->appendOutputTo($output);
        });

        $registry = $this->app->make(ModuleRegistry::class);
        $registry->register(
            'dateien',
            'Dateien',
            'Private Dateiablage mit Dateigruppen, Zugriffsrechten und Versionen',
            roles: ['gruppenverwalter'],
            provider: self::class,
            route: 'dateien.index',
        );
        $registry->navigation('dateien', fn (User $user) => [
            'tabs' => [ModuleNav::tab('Dateien', 'dateien.index')],
            'manage' => GroupService::canManage($user) ? url('/admin/dateien') : null,
        ]);
    }
}
