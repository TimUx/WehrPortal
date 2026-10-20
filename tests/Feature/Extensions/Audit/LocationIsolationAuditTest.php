<?php

declare(strict_types=1);

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

uses()->group('audit');

it('does not expose a vehicle from another location by id', function () {
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-sued']);
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => $north->id])->save();
    DB::table('x_location_user')->insert([
        'location_id' => $north->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $foreign = Vehicle::factory()->create(['ext_location_id' => $south->id]);

    $this->actingAs($member);

    expect(Vehicle::find($foreign->id))->toBeNull();
});

it('keeps a null-location vehicle visible as shared data', function () {
    $location = Location::create(['name' => 'FF Musterstadt', 'slug' => 'audit-musterstadt']);
    $shared = Vehicle::factory()->create();
    $shared->forceFill(['ext_location_id' => null])->save();
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => $location->id])->save();
    DB::table('x_location_user')->insert([
        'location_id' => $location->id,
        'user_id' => $member->id,
        'role' => 'member',
    ]);

    $this->actingAs($member);

    expect(Vehicle::find($shared->id))->not->toBeNull();
});

it('does not apply location filtering to model queries without an authenticated user', function () {
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-sued']);
    $foreign = Vehicle::factory()->create(['ext_location_id' => $south->id]);
    Vehicle::factory()->create(['ext_location_id' => $north->id]);

    auth()->logout();

    expect(Vehicle::find($foreign->id))->not->toBeNull();
});

it('limits a user without a location to shared rows only', function () {
    $location = Location::create(['name' => 'FF Musterstadt', 'slug' => 'audit-musterstadt']);
    $local = Vehicle::factory()->create(['ext_location_id' => $location->id]);
    $shared = Vehicle::factory()->create();
    $shared->forceFill(['ext_location_id' => null])->save();
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => null])->save();

    $this->actingAs($member);

    expect(Vehicle::pluck('id')->all())->toBe([$shared->id])
        ->and(Vehicle::find($local->id))->toBeNull();
});

it('stores module roles without a location dimension', function () {
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-sued']);
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => $north->id])->save();
    DB::table('x_location_user')->insert([
        ['location_id' => $north->id, 'user_id' => $member->id, 'role' => 'member'],
        ['location_id' => $south->id, 'user_id' => $member->id, 'role' => 'member'],
    ]);
    DB::table('module_user')->insert([
        'user_id' => $member->id,
        'module' => 'audit-module',
        'role' => 'leiter',
    ]);

    expect(Schema::getColumnListing('module_user'))->not->toContain('location_id')
        ->and($member->hasModuleRole('audit-module', 'leiter'))->toBeTrue();
});

it('drops a removed location membership after its access cache is cleared', function () {
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-sued']);
    $member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $member->forceFill(['ext_location_id' => $north->id])->save();
    DB::table('x_location_user')->insert([
        ['location_id' => $north->id, 'user_id' => $member->id, 'role' => 'member'],
        ['location_id' => $south->id, 'user_id' => $member->id, 'role' => 'member'],
    ]);
    $access = app(LocationAccess::class);

    expect($access->visibleLocationIds($member))->toEqualCanonicalizing([$north->id, $south->id]);

    DB::table('x_location_user')->where('location_id', $south->id)->where('user_id', $member->id)->delete();
    $access->forget($member);

    expect($access->visibleLocationIds($member))->toBe([$north->id]);
});

it('requires an explicit tenant scope decision for each application table', function () {
    $decisions = collect(config('locations.models', []))
        ->filter(fn (string $class) => class_exists($class))
        ->mapWithKeys(fn (string $class) => [(new $class)->getTable() => 'standortgebunden'])
        ->merge([
            'x_att_lists' => 'standortgebunden',
            'x_att_templates' => 'standortgebunden',
            'x_hc_hydrants' => 'hybrid',
            'x_hc_years' => 'hybrid',
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
        ->map(fn (string $table) => Str::afterLast($table, '.'))
        ->reject(fn (string $table) => in_array($table, $infrastructure, true))
        ->values();
    $unclassified = $tables->diff(array_keys($decisions))->sort()->values()->all();

    expect($unclassified === [])->toBeTrue(
        'Every application table must be explicitly classified as location-bound, cross-location, hybrid, or intentionally global. Unclassified table count: '.count($unclassified),
    )->and(collect($decisions)->every(fn (string $mode) => in_array($mode, ['standortgebunden', 'übergreifend', 'hybrid', 'bewusst global'], true)))->toBeTrue();
});
