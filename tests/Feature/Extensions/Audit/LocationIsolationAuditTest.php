<?php

declare(strict_types=1);

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationAccess;
use Extensions\Locations\Services\ProtectionManifest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Tests\TestCase;

uses()->group('audit');

it('does not expose a vehicle from another location by id', function () {
    /** @var TestCase $this */
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
    /** @var TestCase $this */
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
    /** @var TestCase $this */
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
    $violations = app(ProtectionManifest::class)->violations(config('locations.protection'));

    expect($violations)->toBe([], implode("\n", $violations));
});

it('inventories literal table creations in every core module and extension migration including retired tables', function () {
    $manifest = config('locations.protection');
    $inventoried = array_merge(array_keys($manifest['tables']), array_keys($manifest['infrastructure']), array_keys($manifest['retired_tables']));
    $migrations = array_merge(
        glob(database_path('migrations/*.php')),
        glob(base_path('modules/*/database/migrations/*.php')),
        glob(base_path('extensions/*/database/migrations/*.php')),
    );
    $missing = [];
    foreach ($migrations as $migration) {
        preg_match_all('/Schema::create\(\s*[\'"]([a-z][a-z0-9_]*)[\'"]/', file_get_contents($migration), $matches);
        foreach ($matches[1] as $table) {
            if (! in_array($table, $inventoried, true)) {
                $missing[] = $table.' in '.str_replace(base_path().'/', '', $migration);
            }
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});

it('leaves global administrator visibility unchanged when validating the manifest', function () {
    /** @var TestCase $this */
    $north = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'audit-admin-nord']);
    $south = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'audit-admin-sued']);
    $admin = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => true]);
    $admin->forceFill(['ext_location_id' => $north->id])->save();
    $foreign = Vehicle::factory()->create(['ext_location_id' => $south->id]);
    $this->actingAs($admin);

    $before = Vehicle::find($foreign->id)?->id;
    $violations = app(ProtectionManifest::class)->violations(config('locations.protection'));

    expect($violations)->toBe([], implode("\n", $violations))
        ->and($before)->toBe($foreign->id)
        ->and(Vehicle::find($foreign->id)?->id)->toBe($before);
});
