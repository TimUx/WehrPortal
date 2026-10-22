<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Services\ClothingStockLocationAssignment;
use Extensions\Locations\Services\ClothingStockService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());
afterEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());

it('prevents concurrent matching-key phantoms child edits and parent changes during stock review', function () {
    $location = DB::table('x_locations')->value('id');
    $admin = User::factory()->create(['is_admin' => true]);
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe']);
    $size = DB::table('kk_sizes')->insertGetId(['item_type_id' => $type, 'name' => 'M']);
    $group = DB::table('kk_item_groups')->insertGetId(['item_type_id' => $type, 'name' => 'Einsatz']);
    $stock = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'size_id' => $size, 'group_id' => $group, 'quantity' => 4]);
    $issue = DB::table('kk_issued_items')->insertGetId([
        'item_type_id' => $type, 'size_id' => $size, 'group_id' => $group, 'user_id' => $admin->id,
        'issued_at' => now(), 'returned_at' => now(),
    ]);
    $message = DB::table('kk_equipment_messages')->insertGetId([
        'issued_item_id' => $issue, 'user_id' => $admin->id, 'item_label' => 'Handschuhe', 'body' => 'Privat',
    ]);
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $path = storage_path('framework/testing/stock-lock-'.Str::uuid().'.json');
    file_put_contents($path, json_encode([['table' => 'kk_stock', 'id' => $stock, 'location_id' => $location, 'expected_location_id' => null]]));
    $configuration = DB::connection()->getConfig();
    unset($configuration['name']);
    $other = DB::build($configuration);
    expect($other->selectOne('SELECT pg_backend_pid() AS id')->id)->not->toBe(DB::selectOne('SELECT pg_backend_pid() AS id')->id);
    $other->statement("SET lock_timeout = '100ms'");
    $tested = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$tested, $other, $stock, $type, $size, $group, $issue, $message, $admin, $location) {
        // The first issuance count occurs after all parent/operator locks.
        if ($tested || ! str_contains($query->sql, 'count(*) as aggregate from "kk_issued_items"')) {
            return;
        }
        $tested = true;
        $writes = [
            fn () => $other->table('kk_stock')->where('id', $stock)->update(['quantity' => 5]),
            fn () => $other->table('kk_item_types')->where('id', $type)->update(['name' => 'Geändert']),
            fn () => $other->table('kk_sizes')->where('id', $size)->update(['name' => 'Geändert']),
            fn () => $other->table('kk_item_groups')->where('id', $group)->update(['name' => 'Geändert']),
            fn () => $other->table('x_locations')->where('id', $location)->update(['name' => 'Geändert']),
            fn () => $other->table('users')->where('id', $admin->id)->update(['is_admin' => false]),
            fn () => $other->table('kk_issued_items')->insert([
                'item_type_id' => $type, 'size_id' => $size, 'group_id' => $group, 'user_id' => $admin->id, 'issued_at' => now(),
            ]),
            fn () => $other->table('kk_issued_items')->where('id', $issue)->update(['size_id' => null]),
            fn () => $other->table('kk_equipment_messages')->where('id', $message)->update(['body' => 'Geändert']),
            fn () => $other->table('kk_equipment_messages')->insert(['issued_item_id' => $issue, 'user_id' => $admin->id, 'item_label' => 'Neu', 'body' => 'Neu']),
            fn () => $other->table('x_location_user')->insert(['location_id' => $location, 'user_id' => $admin->id, 'role' => 'member']),
            fn () => $other->statement('ALTER TABLE kk_stock DROP CONSTRAINT kk_stock_ext_location_id_foreign'),
            fn () => $other->statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target'),
            fn () => $other->statement('LOCK TABLE kk_stock IN SHARE ROW EXCLUSIVE MODE'),
        ];
        foreach ($writes as $index => $write) {
            try {
                $other->transaction($write);
            } catch (QueryException $exception) {
                expect($exception->getMessage())->toContain('lock timeout');

                continue;
            }
            test()->fail("Concurrent write {$index} unexpectedly succeeded.");
        }
    });

    try {
        $service = app(ClothingStockLocationAssignment::class);
        $hash = $service->run($path, by: $admin->id, reason: 'Fachlich geprüft')['hash'];
        expect($tested)->toBeTrue();
        $service->run($path, true, $admin->id, 'Fachlich geprüft', $hash, true);
        expect(DB::table('kk_stock')->find($stock))->toMatchObject(['quantity' => 4, 'ext_location_id' => $location])
            ->and(DB::table('kk_equipment_messages')->where('id', $message)->value('body'))->toBe('Privat');
    } finally {
        $other->disconnect();
        File::delete($path);
    }
});

it('serializes stock adjustment before row locks to prevent issuing table-upgrade deadlocks', function () {
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    app(ModuleRegistry::class)->clearCache();
    $admin = User::factory()->create(['is_admin' => true]);
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe']);
    $stock = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'quantity' => 4]);
    config(['locations.clothing_stock_enabled' => true]);
    $configuration = DB::connection()->getConfig();
    unset($configuration['name']);
    $other = DB::build($configuration);
    $other->statement("SET lock_timeout = '100ms'");
    $tested = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$tested, $other) {
        if ($tested || ! str_contains($query->sql, 'from "kk_stock"') || ! str_contains($query->sql, 'for update')) {
            return;
        }
        $tested = true;
        // Issuing must wait at the table boundary, not hold it while adjustment upgrades.
        expect(fn () => $other->transaction(fn () => $other->statement('LOCK TABLE kk_stock IN SHARE ROW EXCLUSIVE MODE')))
            ->toThrow(QueryException::class, 'lock timeout');
    });
    try {
        app(ClothingStockService::class)->adjust($admin, $stock, 8);
        expect($tested)->toBeTrue()->and(DB::table('kk_stock')->where('id', $stock)->value('quantity'))->toBe(8);
    } finally {
        $other->disconnect();
    }
});
