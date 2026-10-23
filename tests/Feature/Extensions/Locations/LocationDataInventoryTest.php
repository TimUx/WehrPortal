<?php

use Extensions\Locations\Console\AuditLocationDataCommand;
use Extensions\Locations\Services\LocationDataInventory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

it('counts unassigned records without exporting content or assigning a default location', function () {
    $vehicle = Vehicle::factory()->create(['name' => 'Inventur ohne Inhaltsausgabe']);
    DB::table('vehicles')->where('id', $vehicle->id)->update(['ext_location_id' => null]);

    $before = DB::table('vehicles')->where('id', $vehicle->id)->first();
    $rows = app(LocationDataInventory::class)->rows();
    $entry = collect($rows)->firstWhere('table', 'vehicles');

    expect($entry['rows'])->toBe(1)
        ->and($entry['unassigned'])->toBe(1)
        ->and($entry['review'])->toBe('null_ownership_review')
        ->and(json_encode($rows))->not->toContain($vehicle->name)
        ->and(DB::table('vehicles')->where('id', $vehicle->id)->first())->toEqual($before);
});

it('distinguishes inherited or global ownership from direct location ownership', function () {
    $rows = collect(app(LocationDataInventory::class)->rows());
    expect($rows->firstWhere('table', 'booking_comments')['unassigned'])->toBeNull()
        ->and($rows->firstWhere('table', 'booking_comments')['review'])->toBe('parent_or_global_policy_review')
        ->and($rows->firstWhere('table', 'settings')['location_column'])->toBeNull();
});

it('refuses an incomplete manifest before reporting inventory', function () {
    config(['locations.protection.tables.vehicles' => null]);
    expect(fn () => app(LocationDataInventory::class)->rows())->toThrow(RuntimeException::class);
});

it('offers a read only inventory command without a mutation option', function () {
    expect(Artisan::call(AuditLocationDataCommand::class, ['--json' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['read_only'])->toBeTrue()
        ->and($output['enforcement_verified'])->toBeFalse()
        ->and($output['tables'])->toBeArray();
});
