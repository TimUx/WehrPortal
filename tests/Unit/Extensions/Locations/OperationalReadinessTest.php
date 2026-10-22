<?php

use Extensions\Locations\Console\CheckOperationalReadinessCommand;
use Extensions\Locations\Services\LocationRelationInventory;
use Extensions\Locations\Services\OperationalReadiness;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

// The inventory uses an independent read-only connection, so fixtures must be committed.
beforeEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());
afterEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());

it('keeps empty technical inventory separate from unresolved release acceptance', function () {
    $report = app(OperationalReadiness::class)->report();

    expect($report)->toMatchArray([
        'read_only' => true, 'release_ready' => false, 'enforcement_verified' => false, 'status' => 'blocked',
    ])
        ->and($report['technical_inventory']['status'])->toBe('CHECK')
        ->and($report['technical_inventory']['summary']['tables'])->toBeGreaterThan(0)
        ->and($report['technical_inventory']['summary']['relations'])->toBeGreaterThan(0)
        ->and($report['technical_inventory']['findings']['missing_parent']['observed_count'])->toBe(0)
        ->and(array_keys($report['release_blockers']))->toBe([
            'p1_private_acceptance', 'p3_operator_ownership_restore', 'p4_p5_remaining_module_paths',
            'p6_module_views_profile_policy', 'p7_operational_release_acceptance',
        ]);
});

it('reports dirty data as blocked and exposes only finite aggregate metadata without writes', function () {
    config(['locations.protection.tables.ar_equipment.target_gap' => 'Private Manifestnotiz']);
    $location = DB::table('x_locations')->insertGetId(['name' => 'Private Wache', 'slug' => 'private-readiness']);
    $category = DB::table('ar_categories')->insertGetId(['name' => 'Private Kategorie', 'ext_location_id' => $location]);
    DB::table('ar_equipment')->insert([
        'code' => 'PRIVATE-READINESS', 'name' => 'Vertrauliches Gerät', 'notes' => 'Private Adresse',
        'category_id' => $category, 'ext_location_id' => null,
    ]);
    Mail::fake();
    Notification::fake();
    $tables = ['ar_equipment', 'ar_categories', 'x_locations', 'activity_logs', 'mail_logs', 'notifications'];
    $snapshot = fn () => array_map(fn (string $table) => DB::table($table)->orderByRaw('1')->get()->toJson(), $tables);
    $before = $snapshot();
    $connections = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$connections) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $connections[] = $query->connection;
            $mode = $query->connection->selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
            expect($mode->isolation)->toBe('repeatable read')->and($mode->read_only)->toBe('on');
        }
    });
    $report = app(OperationalReadiness::class)->report();
    expect($report['status'])->toBe('blocked')
        ->and($report['release_ready'])->toBeFalse()
        ->and($report['technical_inventory']['findings']['null_ownership']['observed_count'])->toBeGreaterThan(0)
        ->and(count($connections))->toBe(1)
        ->and($connections[0]->transactionLevel())->toBe(0)
        ->and($connections[0]->getRawPdo())->toBeNull()
        ->and($snapshot())->toBe($before);
    foreach ([true, false] as $json) {
        expect(Artisan::call('locations:check-readiness', ['--json' => $json]))->toBe(2);
        expect(Artisan::output())->not->toContain(
            'Private', 'PRIVATE-READINESS', 'Vertrauliches', 'ar_equipment', 'ar_categories',
            'category_id', 'ext_location_id', 'parent_table', 'target_gap', 'SQLSTATE',
        );
    }
    expect($snapshot())->toBe($before);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('retains unknown counts for optional absent schema and disabled extensions', function () {
    config(['oidc.enabled' => false, 'locations.enabled' => false]);
    DB::getSchemaBuilder()->drop('x_oidc_identities');
    $report = app(OperationalReadiness::class)->report();
    expect($report['status'])->toBe('blocked')
        ->and($report['release_ready'])->toBeFalse()
        ->and($report['technical_inventory']['summary']['tables_not_inspected'])->toBeGreaterThan(0)
        ->and($report['technical_inventory']['summary']['relations_not_inspected'])->toBeGreaterThan(0)
        ->and($report['technical_inventory']['findings']['missing_parent']['count'])->toBeNull()
        ->and($report['technical_inventory']['findings']['missing_parent']['uninspected'])->toBeGreaterThan(0);
});

it('never certifies zero findings even when every supplied technical count is zero', function () {
    $snapshot = app(LocationRelationInventory::class)->report();
    foreach (['tables', 'relations'] as $group) {
        foreach ($snapshot[$group] as &$entry) {
            $entry['availability'] = 'available';
            $entry['counts'] = $group === 'tables'
                ? ['null_ownership' => 0, 'missing_location' => 0, 'inactive_location' => 0]
                : ['null_reference' => 0, 'missing_parent' => 0, 'parent_null_ownership' => 0, 'child_null_ownership' => 0, 'location_disagreement_review' => 0];
        }
        unset($entry);
    }
    $snapshot['limitations'] = [];
    $inventory = Mockery::mock(LocationRelationInventory::class);
    $inventory->shouldReceive('report')->once()->andReturn($snapshot);
    $report = (new OperationalReadiness($inventory))->report();
    expect($report['technical_inventory']['findings']['missing_parent']['count'])->toBe(0)
        ->and($report['technical_inventory']['summary']['limitations'])->toBe(0)
        ->and($report['status'])->toBe('blocked')
        ->and($report['release_ready'])->toBeFalse()
        ->and($report['enforcement_verified'])->toBeFalse()
        ->and($report['release_blockers'])->toHaveCount(5);
});

it('fails closed for malformed manifests and required schema without disclosing details', function (string $change) {
    if ($change === 'manifest') {
        config(['locations.protection.tables.ar_equipment.parents.0.child_column' => 'private payload; select']);
    } else {
        DB::getSchemaBuilder()->table('ar_categories', fn (Blueprint $table) => $table->renameColumn('id', 'private_parent_column'));
    }
    foreach ([true, false] as $json) {
        expect(Artisan::call('locations:check-readiness', ['--json' => $json]))->toBe(1);
        $output = Artisan::output();
        expect($output)->not->toContain('payload', 'private_parent_column', 'SQLSTATE', 'select ');
        if ($json) {
            $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            expect($report)->toMatchArray([
                'status' => 'unavailable', 'read_only' => true, 'release_ready' => false,
                'enforcement_verified' => false, 'error' => 'operational_readiness_unavailable',
            ])->and($report['technical_inventory'])->toBe(['status' => 'unavailable', 'summary' => null, 'findings' => null]);
        }
    }
})->with(['manifest', 'schema']);

it('rejects incomplete snapshot fields instead of treating them as a clean inventory', function (string $field) {
    $snapshot = app(LocationRelationInventory::class)->report();
    if ($field === 'finding') {
        $index = array_search('available', array_column($snapshot['relations'], 'availability'), true);
        unset($snapshot['relations'][$index]['counts']['missing_parent']);
    } else {
        unset($snapshot[$field]);
    }
    $inventory = Mockery::mock(LocationRelationInventory::class);
    $inventory->shouldReceive('report')->once()->andReturn($snapshot);
    $this->app->instance(LocationRelationInventory::class, $inventory);
    expect(Artisan::call('locations:check-readiness', ['--json' => true]))->toBe(1)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('unavailable');
})->with(['read_only', 'snapshot', 'automatic_target_inference', 'row_scope', 'tables', 'relations', 'limitations', 'finding']);

it('sanitizes injected SQL exceptions in both output formats', function () {
    $inventory = Mockery::mock(LocationRelationInventory::class);
    $inventory->shouldReceive('report')->twice()->andThrow(new QueryException(
        'pgsql', 'select private_address from private_table', ['private binding'],
        new RuntimeException('SQLSTATE private exception'),
    ));
    $this->app->instance(LocationRelationInventory::class, $inventory);
    foreach ([true, false] as $json) {
        expect(Artisan::call('locations:check-readiness', ['--json' => $json]))->toBe(1)
            ->and(Artisan::output())->not->toContain('private_address', 'private_table', 'binding', 'SQLSTATE', 'private exception');
    }
});

it('enforces database read only mode and reports attempted writes as unavailable', function () {
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $query->connection->table('ar_equipment')->whereRaw('false')->update(['notes' => 'private forbidden write']);
        }
    });
    expect(Artisan::call('locations:check-readiness', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    expect($output)->not->toContain('SQLSTATE', 'private forbidden write', 'update ')
        ->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['status'])->toBe('unavailable');
});

it('provides a read only JSON and human exit contract without bypass options', function () {
    expect(array_keys((new CheckOperationalReadinessCommand)->getDefinition()->getOptions()))->toBe(['json']);
    expect(Artisan::call('locations:check-readiness', ['--json' => true]))->toBe(2);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['status'])->toBe('blocked')->and($report['release_ready'])->toBeFalse();
    expect(Artisan::call('locations:check-readiness'))->toBe(2)
        ->and(Artisan::output())->toContain(
            'Nur lesend', 'CHECK', 'keine Betriebsfreigabe', 'keine Abnahme',
            'Freigabe gesperrt', 'P1:', 'P3:', 'P4/P5:', 'P6:', 'P7:',
        );
});
