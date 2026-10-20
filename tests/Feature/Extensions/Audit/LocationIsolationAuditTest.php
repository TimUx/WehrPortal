<?php

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

uses()->group('audit');

it('does not expose a shared-null vehicle to another location', function () {
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-sued']);
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => $north->id])->save();
    DB::table('x_location_user')->insert([
        'location_id' => $north->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $shared = Vehicle::factory()->create();
    $shared->forceFill(['ext_location_id' => null])->save();

    $this->actingAs($member);

    expect(Vehicle::find($shared->id))->toBeNull();
});

it('requires an explicit tenant scope decision for each application table', function () {
    $decisions = collect(config('locations.models', []))
        ->filter(fn (string $class) => class_exists($class))
        ->mapWithKeys(fn (string $class) => [(new $class)->getTable() => 'standortgebunden'])
        ->merge([
            'x_attendance_lists' => 'standortgebunden',
            'x_attendance_templates' => 'standortgebunden',
            'x_hc_hydrants' => 'hybrid',
            'x_hc_inspection_years' => 'hybrid',
            'x_hc_inspections' => 'hybrid',
            'x_files_spaces' => 'hybrid',
            'x_files_groups' => 'hybrid',
            'announcements' => 'hybrid',
        ])
        ->all();

    $infrastructure = [
        'cache',
        'cache_locks',
        'failed_jobs',
        'jobs',
        'job_batches',
        'migrations',
        'password_reset_tokens',
        'sessions',
    ];
    $tables = collect(Schema::getTableListing())
        ->reject(fn (string $table) => in_array($table, $infrastructure, true))
        ->values();
    $unclassified = $tables->diff(array_keys($decisions))->sort()->values()->all();

    expect($unclassified === [])->toBeTrue(
        'Every application table must be explicitly classified as location-bound, cross-location, hybrid, or intentionally global. Unclassified table count: '.count($unclassified),
    )->and(collect($decisions)->every(fn (string $mode) => in_array($mode, ['standortgebunden', 'übergreifend', 'hybrid', 'bewusst global'], true)))->toBeTrue();
});
