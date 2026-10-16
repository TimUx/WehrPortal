<?php

namespace Extensions\Oidc;

use App\Models\User;
use App\Services\AuthMethodRegistry;
use Extensions\Oidc\Console\DisableCommand;
use Extensions\Oidc\Console\MakeLocalAdminCommand;
use Extensions\Oidc\Filament\OidcPlugin;
use Extensions\Oidc\Services\LockoutGuard;
use Extensions\Oidc\Support\OidcAuthMethod;
use Extensions\Oidc\Support\OidcSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Login through an external identity provider (OIDC, e.g. Keycloak). Switched off completely with
 * OIDC_ENABLED=false; OIDC_FORCE_DISABLED=true only turns the IdP login off (emergency).
 */
class OidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/oidc.php', 'oidc');

        // The admin page is added as a plugin once all panels are registered but before Filament
        // loads its routes (which happens while the providers boot)
        $this->app->booting(function () {
            if (config('oidc.enabled')) {
                Filament::getPanel('admin')?->plugin(OidcPlugin::make());
            }
        });
    }

    public function boot(): void
    {
        if (! config('oidc.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadTranslationsFrom(__DIR__.'/lang', 'oidc');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'oidc');

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(__DIR__.'/routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([DisableCommand::class, MakeLocalAdminCommand::class]);
        }

        $registry = $this->app->make(AuthMethodRegistry::class);
        $registry->register(new OidcAuthMethod($this->app->make(OidcSettings::class)));
        $registry->resolveLocalAdminUsing(fn (User $user) => (bool) $user->getAttribute('ext_local_admin'));

        // New admins are local system admins by default (they can be excluded on the OIDC admin page)
        User::saving(function (User $user) {
            if ($user->is_admin && ($user->isDirty('is_admin') || ! $user->exists) && ! $user->isDirty('ext_local_admin')) {
                $user->setAttribute('ext_local_admin', true);
            }
        });
        User::updating(fn (User $user) => LockoutGuard::check($user));
        User::deleting(fn (User $user) => LockoutGuard::check($user, deleting: true));

    }
}
