<?php

namespace Extensions\E2e;

use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

/**
 * Freezes the clock for the E2E screenshot run, so the example data and every date on the pages are the
 * same in each run and the screenshots only change when the UI does. Does nothing unless
 * E2E_FROZEN_NOW is set, and never in production or in the Pest tests.
 */
class E2eServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/e2e.php', 'e2e');

        $now = config('e2e.frozen_now');
        if (filled($now) && ! $this->app->isProduction() && ! $this->app->runningUnitTests()) {
            Carbon::setTestNow(Carbon::parse((string) $now)->utc());
        }
    }
}
