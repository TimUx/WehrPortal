<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshCommittedDatabase
{
    use DatabaseMigrations;

    public function runDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->assertSuccessful();

        $this->beforeApplicationDestroyed(function () {
            // These suites require a fresh schema, not the migration history's down() paths.
            $this->artisan('migrate:fresh')->assertSuccessful();

            RefreshDatabaseState::$migrated = false;
        });
    }
}
