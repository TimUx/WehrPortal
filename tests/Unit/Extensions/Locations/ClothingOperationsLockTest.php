<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingOperations;
use Extensions\Locations\Services\LocationModuleRoles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshCommittedDatabase;

uses(RefreshCommittedDatabase::class);

it('serializes transfers and reviews before row locks and freezes quantity provenance and fresh authority', function () {
    config(['locations.clothing_stock_enabled' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    app(ModuleRegistry::class)->clearCache();
    $admin = User::factory()->create(['is_admin' => true]);
    $a = Location::create(['name' => 'Wache Nord', 'slug' => 'lock-operation-nord']);
    $b = Location::create(['name' => 'Wache Süd', 'slug' => 'lock-operation-sued']);
    $worker = User::factory()->create(['ext_location_id' => $a->id]);
    DB::table('x_location_user')->insert(['location_id' => $b->id, 'user_id' => $worker->id, 'role' => 'member']);
    $roles = app(LocationModuleRoles::class);
    $grant = $roles->grant($admin, $a->id, $worker->id, 'kleiderkammer', 'bestand', 'Bestand');
    $roles->grant($admin, $b->id, $worker->id, 'kleiderkammer', 'bestand', 'Bestand');
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe']);
    $from = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'quantity' => 12, 'ext_location_id' => $a->id]);
    $to = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'quantity' => 5, 'ext_location_id' => $b->id]);
    $issue = DB::table('kk_issued_items')->insertGetId([
        'user_id' => $worker->id, 'item_type_id' => $type, 'quantity' => 2,
        'ext_source_stock_id' => $from, 'issued_at' => now()->subDays(2), 'returned_at' => now()->subDay(),
    ]);
    config(['locations.clothing_stock_enabled' => true]);
    $configuration = DB::connection()->getConfig();
    unset($configuration['name']);
    $other = DB::build($configuration);
    $other->statement("SET lock_timeout = '100ms'");
    $tested = [];
    $selectionLocked = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$tested, &$selectionLocked, $other, $worker, $grant, $from, $to, $a, $b, $issue, $admin) {
        if (str_contains($query->sql, 'LOCK TABLE kk_stock IN SHARE ROW EXCLUSIVE MODE')) {
            $selectionLocked = true;
        }
        if (str_contains($query->sql, 'for update')) {
            expect($selectionLocked)->toBeTrue();
        }
        $phase = str_contains($query->sql, 'insert into "x_clothing_stock_transfers"') ? 'transfer'
            : (str_contains($query->sql, 'select * from "kk_issued_items"') && str_contains($query->sql, 'for update') ? 'review' : null);
        if ($phase === null || isset($tested[$phase])) {
            return;
        }
        $tested[$phase] = true;
        $writes = [
            fn () => $other->table('kk_stock')->where('id', $from)->update(['quantity' => 1]),
            fn () => $other->table('kk_stock')->where('id', $to)->update(['quantity' => 1]),
            fn () => $other->statement('LOCK TABLE kk_stock IN SHARE ROW EXCLUSIVE MODE'),
            fn () => $other->table('modules')->where('key', 'kleiderkammer')->update(['is_active' => false]),
            fn () => $other->table('x_locations')->where('id', $b->id)->update(['is_active' => false]),
        ];
        if ($phase === 'transfer') {
            $writes[] = fn () => $other->table('users')->where('id', $worker->id)->update(['is_active' => false]);
            $writes[] = fn () => $other->table('x_locations')->where('id', $a->id)->update(['is_active' => false]);
            $writes[] = fn () => $other->table('x_location_module_roles')->where('id', $grant->id)->update(['revoked_at' => now(), 'revoked_by' => $admin->id, 'revoke_reason' => 'Entzogen', 'revoked_source' => 'admin']);
            $writes[] = fn () => $other->table('x_location_user')->where('user_id', $worker->id)->delete();
        }
        if ($phase === 'review') {
            $writes[] = fn () => $other->table('users')->where('id', $admin->id)->update(['is_admin' => false]);
            $writes[] = fn () => $other->table('kk_issued_items')->where('id', $issue)->update(['quantity' => 9]);
        }
        foreach ($writes as $write) {
            expect(fn () => $other->transaction($write))->toThrow(QueryException::class, 'lock timeout');
        }
    });

    try {
        $operations = app(ClothingOperations::class);
        $operations->transfer($worker, $from, $to, 3, 'Umlagerung', (string) Str::uuid());
        $selectionLocked = false;
        $preview = $operations->correctHistory($admin, $issue, $to, 'Historisch geprüft', 'Beleg TEST-LOCK');
        expect($tested)->toBe(['transfer' => true, 'review' => true]);
        $operations->correctHistory($admin, $issue, $to, 'Historisch geprüft', 'Beleg TEST-LOCK', true, $preview['hash'], true);
        expect(DB::table('kk_stock')->where('id', $from)->value('quantity'))->toBe(9)
            ->and(DB::table('kk_stock')->where('id', $to)->value('quantity'))->toBe(8)
            ->and(DB::table('kk_issued_items')->where('id', $issue)->value('ext_source_stock_id'))->toBe($from)
            ->and(DB::table('x_clothing_history_corrections')->count())->toBe(1);
    } finally {
        $other->disconnect();
    }
});

it('waits for existing authority row locks before taking capability table locks', function (string $pending) {
    config(['locations.clothing_stock_enabled' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true]);
    app(ModuleRegistry::class)->clearCache();
    $admin = User::factory()->create(['is_admin' => true]);
    $a = Location::create(['name' => 'Wache Nord', 'slug' => 'authority-lock-nord']);
    $b = Location::create(['name' => 'Wache Süd', 'slug' => 'authority-lock-sued']);
    $worker = User::factory()->create(['ext_location_id' => $a->id]);
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe']);
    $from = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'quantity' => 12, 'ext_location_id' => $a->id]);
    $to = DB::table('kk_stock')->insertGetId(['item_type_id' => $type, 'quantity' => 5, 'ext_location_id' => $b->id]);
    config(['locations.clothing_stock_enabled' => true]);
    $configuration = DB::connection()->getConfig();
    unset($configuration['name']);
    $other = DB::build($configuration);
    $other->beginTransaction();
    // Existing grant() and member removal start with user FOR UPDATE locks.
    $other->table('users')->whereIn('id', $pending === 'grant' ? [$admin->id, $worker->id] : [$admin->id])
        ->orderBy('id')->lockForUpdate()->get();
    if ($pending === 'grant') {
        $other->table('x_locations')->where('id', $a->id)->lockForUpdate()->first();
    }
    DB::statement("SET lock_timeout = '100ms'");
    $capabilityTablesLocked = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$capabilityTablesLocked) {
        if (str_starts_with($query->sql, 'LOCK TABLE') && str_contains($query->sql, 'x_location_user')) {
            $capabilityTablesLocked = true;
        }
    });
    try {
        expect(fn () => app(ClothingOperations::class)->transfer($admin, $from, $to, 1, 'Umlagerung', (string) Str::uuid()))
            ->toThrow(QueryException::class, 'lock timeout');
        expect($capabilityTablesLocked)->toBeFalse()
            ->and(DB::table('x_clothing_stock_transfers')->count())->toBe(0);
    } finally {
        $other->rollBack();
        $other->disconnect();
        DB::statement("SET lock_timeout = '0'");
    }
    app(ClothingOperations::class)->transfer($admin, $from, $to, 1, 'Umlagerung nach Freigabe', (string) Str::uuid());
    expect(DB::table('x_clothing_stock_transfers')->count())->toBe(1);

    $locks = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$locks) {
        if (str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });
    app(LocationModuleRoles::class)->grant($admin, $a->id, $worker->id, 'kleiderkammer', 'bestand', 'Bestand');
    expect($locks[0])->toContain('from "users"', 'order by "id" asc')
        ->and(array_search(true, array_map(fn (string $sql) => str_contains($sql, 'from "x_locations"'), $locks), true))->toBeGreaterThan(0);
})->with(['grant', 'member_removal']);
