<?php

namespace Extensions\EinsatzberichtPlus;

use Extensions\EinsatzberichtPlus\Livewire\Extras;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Additions to the Einsatzbericht that the core form lacks: Kostenpflicht, beteiligte Personen,
 * Verdienstausfall, a free-text external vehicle and Sollbesatzung hints. Own tables and views; the only
 * core integration is one @includeIf in the report editor. Without the Einsatzbericht module or with
 * EINSATZBERICHT_PLUS_ENABLED=false nothing is loaded.
 */
class EinsatzberichtPlusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/einsatzbericht-plus.php', 'einsatzbericht-plus');
    }

    public function boot(): void
    {
        if (! config('einsatzbericht-plus.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'ebp');
        Livewire::component('ebp.extras', Extras::class);
    }
}
