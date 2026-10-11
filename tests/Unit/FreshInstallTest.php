<?php

use App\Models\Group;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

// Runs the full migration history like a new production install.
// Since 1.5 (Wehr-Einstellungen) fresh installs start empty — the base data
// migration only seeds on existing installs that already have vehicles.
// Demo data is provided separately by DemoDataSeeder.
// Not a Feature test: RefreshDatabase's transaction can't wrap migrate:fresh.
test('a fresh install migrates cleanly with no vehicles or groups', function () {
    $this->app['env'] = 'production';

    try {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        // Fresh installs start empty — data comes from DemoDataSeeder, not migrations
        expect(Vehicle::count())->toBe(0)
            ->and(Group::count())->toBe(0);

        // Running it again (as on every container start) stays idempotent
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        expect(Vehicle::count())->toBe(0);
    } finally {
        // Leave an empty schema behind for the feature tests in this process
        $this->app['env'] = 'testing';
        $this->artisan('migrate:fresh');
    }
});
