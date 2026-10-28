<?php

use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Lehrgaenge\src\Models\Course;

// Not a Feature test: RefreshDatabase's transaction can't wrap migrate:fresh.
test('a fresh WehrPortal install creates the current schema without predecessor data', function () {
    $this->app['env'] = 'production';

    try {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        // Fresh installs contain only the required departments; operational data comes from the seeder.
        expect(Vehicle::count())->toBe(0)
            ->and(Group::count())->toBe(6)
            ->and(User::count())->toBe(0)
            ->and(Course::count())->toBe(0);
        foreach (['is_decider', 'can_post_announcements', 'department'] as $column) {
            expect(Schema::hasColumn('users', $column))->toBeFalse();
        }
        foreach (['kk_members', 'group_functions', 'group_function_user', 'lq_offerings', 'lq_certificates', 'lq_course_prerequisites'] as $table) {
            expect(Schema::hasTable($table))->toBeFalse();
        }
        expect(Schema::hasColumn('x_locations', 'crest_data'))->toBeTrue()
            ->and(Schema::hasColumn('ar_locations', 'ext_location_id'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('ar_stocktakes'))->contains('name', 'ar_stocktakes_ext_location_fk'))->toBeTrue()
            ->and(Schema::hasColumn('as_operations', 'ext_location_id'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('as_operations'))->contains('name', 'as_operations_ext_location_fk'))->toBeTrue()
            ->and(Schema::hasColumn('kk_issued_items', 'ext_source_stock_id'))->toBeTrue()
            ->and(Schema::hasTable('member_functions'))->toBeTrue()
            ->and(Schema::hasTable('lq_record_sessions'))->toBeTrue();

        // Running it again (as on every container start) stays idempotent
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        expect(Vehicle::count())->toBe(0);
    } finally {
        // Leave an empty schema behind for the feature tests in this process
        $this->app['env'] = 'testing';
        $this->artisan('migrate:fresh');
    }
});
