<?php

use Extensions\Locations\Services\LocationDataInventory;
use Extensions\Locations\Services\LocationRelationInventory;
use Extensions\Locations\Services\ProtectionManifest;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function locationRelationFixture(): array
{
    $north = DB::table('x_locations')->insertGetId(['name' => 'FF Musterstadt Nord', 'slug' => 'relation-north']);
    $south = DB::table('x_locations')->insertGetId(['name' => 'FF Musterstadt Süd', 'slug' => 'relation-south']);
    $inactive = DB::table('x_locations')->insertGetId(['name' => 'Alte Wache', 'slug' => 'relation-old', 'is_active' => false]);
    $category = DB::table('ar_categories')->insertGetId(['name' => 'Vertraulicher Katalog', 'ext_location_id' => $north]);
    $shared = DB::table('ar_categories')->insertGetId(['name' => 'Gemeinsamer Katalog', 'ext_location_id' => null]);
    foreach ([
        [$north, $category], [$south, $category], [null, $category],
        [$north, $shared], [$inactive, null],
    ] as $index => [$owner, $parent]) {
        DB::table('ar_equipment')->insert([
            'code' => 'VERTRAULICH-'.$index, 'name' => 'Private Gerätebezeichnung',
            'notes' => 'Niemals ausgeben', 'ext_location_id' => $owner, 'category_id' => $parent,
        ]);
    }

    return compact('north', 'south', 'inactive', 'category', 'shared');
}

function locationRelationEdge(array $report, string $table, string $column): array
{
    return collect($report['relations'])->first(fn (array $edge) => $edge['table'] === $table && $edge['child_column'] === $column);
}

it('counts finite direct disagreements and NULL ambiguities without inferring owners or sharing', function () {
    locationRelationFixture();
    $report = app(LocationRelationInventory::class)->report();
    $equipment = collect($report['tables'])->firstWhere('table', 'ar_equipment');
    $edge = locationRelationEdge($report, 'ar_equipment', 'category_id');
    expect($equipment['rows'])->toBe(5)
        ->and($equipment['counts'])->toBe(['null_ownership' => 1, 'missing_location' => 0, 'inactive_location' => 1])
        ->and($equipment['source_null_rule'])->toBe('shared')
        ->and($edge['counts'])->toBe([
            'null_reference' => 1, 'missing_parent' => 0, 'parent_null_ownership' => 1,
            'child_null_ownership' => 1, 'location_disagreement_review' => 1,
        ])
        ->and($edge['source_null_rule'])->toBe('inherit')
        ->and($edge['ownership_comparison'])->toBe('independent_direct_locations_review_only')
        ->and(locationRelationEdge($report, 'feedback_messages', 'user_id')['source_null_rule'])->toBe('retain')
        ->and(locationRelationEdge($report, 'activity_logs', 'user_id')['ownership_comparison'])->toBe('audit_actor_ownership_not_inferred')
        ->and(locationRelationEdge($report, 'notifications', 'notifiable_id')['availability'])->toBe('polymorphic_not_inspected')
        ->and($report['enforcement_verified'])->toBeFalse()
        ->and($report['automatic_target_inference'])->toBeFalse()
        ->and($report['edge_count_policy'])->toBe('per_edge_counts_not_unique_affected_totals');
    $limitations = collect($report['limitations']);
    expect($limitations->where('table', 'ar_equipment')->pluck('category')->all())->toContain('multi_parent_policy_not_verified')
        ->and($limitations->where('table', 'notifications')->pluck('category')->all())->toContain('polymorphic_not_verified', 'non_direct_ownership_not_verified');
});

it('counts missing nonnull parents and owners without treating NULL references as missing', function () {
    locationRelationFixture();
    DB::statement("SET session_replication_role = 'replica'");
    try {
        DB::table('ar_equipment')->insert([
            'name' => 'Fehlender Elternsatz', 'code' => 'ORPHAN', 'category_id' => 987654321, 'ext_location_id' => 987654321,
        ]);
    } finally {
        DB::statement("SET session_replication_role = 'origin'");
    }
    $report = app(LocationRelationInventory::class)->report();
    expect(collect($report['tables'])->firstWhere('table', 'ar_equipment')['counts']['missing_location'])->toBe(1)
        ->and(locationRelationEdge($report, 'ar_equipment', 'category_id')['counts']['missing_parent'])->toBe(1)
        ->and(locationRelationEdge($report, 'ar_equipment', 'category_id')['counts']['null_reference'])->toBe(1);
});

it('retains the default command contract and exposes only aggregate metadata in both relation formats', function () {
    locationRelationFixture();
    Mail::fake();
    Notification::fake();
    $tables = ['ar_equipment', 'ar_categories', 'x_locations', 'activity_logs', 'notifications', 'mail_logs'];
    $snapshot = fn () => array_map(fn (string $table) => DB::table($table)->orderByRaw('1')->get()->toJson(), $tables);
    $before = $snapshot();
    $expected = app(LocationDataInventory::class)->rows();
    expect(Artisan::call('locations:audit-data', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'read_only' => true, 'scope' => 'direct_location_ownership_counts_only',
        'enforcement_verified' => false, 'tables' => $expected,
    ]);
    foreach ([true, false] as $json) {
        expect(Artisan::call('locations:audit-data', ['--relations' => true, '--json' => $json]))->toBe(0);
        $output = Artisan::output();
        expect($output)->toContain('ar_equipment', 'location_disagreement_review', 'polymorphic_not')
            ->not->toContain('VERTRAULICH', 'Private Gerätebezeichnung', 'Niemals', 'FF Musterstadt', 'Vertraulicher Katalog');
        if ($json) {
            expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['snapshot'])->toBe('postgresql_repeatable_read_read_only');
        }
    }
    expect($snapshot())->toBe($before);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('reports optional absent schema separately and inspects retained schema even when disabled', function () {
    config(['oidc.enabled' => false, 'locations.enabled' => false]);
    DB::getSchemaBuilder()->drop('x_oidc_identities');
    $report = app(LocationRelationInventory::class)->report();
    expect(collect($report['tables'])->firstWhere('table', 'x_oidc_identities'))->toMatchArray([
        'availability' => 'optional_absent_not_inspected', 'extension_state' => 'disabled', 'rows' => null, 'counts' => null,
    ])
        ->and(collect($report['tables'])->firstWhere('table', 'x_locations'))->toMatchArray([
            'availability' => 'available', 'extension_state' => 'disabled',
        ])
        ->and(locationRelationEdge($report, 'x_oidc_identities', 'user_id')['availability'])->toBe('optional_schema_absent_not_inspected');
});

it('reports optional absent provenance without a false passing count', function () {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_26_000000_record_clothing_issuance_stock_source.php');
    DB::transaction(fn () => $migration->down());
    expect(locationRelationEdge(app(LocationRelationInventory::class)->report(), 'kk_issued_items', 'ext_source_stock_id'))->toMatchArray([
        'availability' => 'optional_column_absent_not_inspected', 'counts' => null,
    ]);
});

it('validates metadata and parent columns before counting and sanitizes every failure', function (string $change) {
    if ($change === 'malformed') {
        config(['locations.protection.tables.ar_equipment.parents.0.child_column' => 'private payload; select']);
    } elseif ($change === 'parent_column') {
        config(['locations.protection.tables.ar_equipment.parents.1.column' => 'nonexistent_private_column']);
    } else {
        config(['locations.protection.tables.ar_equipment.parents.1.column' => 'name']);
    }
    $counts = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$counts) {
        if (str_contains($query->sql, 'count(*)')) {
            $counts[] = $query->sql;
        }
    });
    expect(Artisan::call('locations:audit-data', ['--relations' => true, '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'read_only' => true, 'enforcement_verified' => false, 'error' => 'location_relations_unavailable',
    ])->and($counts)->toBe([]);
    expect(Artisan::call('locations:audit-data', ['--relations' => true]))->toBe(1)
        ->and(Artisan::output())->not->toContain('payload', 'nonexistent_private_column', 'SQLSTATE');
})->with(['malformed', 'parent_column', 'mismatched_foreign_key']);

it('uses one repeatable read read only snapshot and always rolls it back and disconnects', function (bool $fail) {
    locationRelationFixture();
    $isolated = $mode = null;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$isolated, &$mode, $fail) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $isolated = $query->connection;
            $mode = $isolated->selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
            if ($fail) {
                $isolated->table('ar_equipment')->whereRaw('false')->update(['notes' => 'forbidden private write']);
            }
        }
    });
    DB::beginTransaction();
    try {
        $before = DB::selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        if ($fail) {
            expect(fn () => app(LocationRelationInventory::class)->report())->toThrow(RuntimeException::class, LocationRelationInventory::FAILURE);
        } else {
            app(LocationRelationInventory::class)->report();
        }
        expect($mode->isolation)->toBe('repeatable read')
            ->and($mode->read_only)->toBe('on')
            ->and($isolated)->toBeInstanceOf(Connection::class)
            ->and($isolated->transactionLevel())->toBe(0)
            ->and($isolated->getRawPdo())->toBeNull()
            ->and(DB::transactionLevel())->toBe(1)
            ->and(DB::selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only"))->toEqual($before);
    } finally {
        DB::rollBack();
    }
})->with([false, true]);

it('holds schema counts and edge counts at one snapshot during concurrent commits', function () {
    $fixture = locationRelationFixture();
    $inserted = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$inserted, $fixture) {
        if (! $inserted && str_contains($query->sql, 'count(*)') && str_contains($query->sql, '"ar_equipment"')) {
            $inserted = true;
            DB::table('ar_equipment')->insert([
                'code' => 'CONCURRENT', 'name' => 'Concurrent', 'category_id' => $fixture['category'], 'ext_location_id' => $fixture['south'],
            ]);
        }
    });
    $report = app(LocationRelationInventory::class)->report();
    expect($inserted)->toBeTrue()
        ->and(collect($report['tables'])->firstWhere('table', 'ar_equipment')['rows'])->toBe(5)
        ->and(locationRelationEdge($report, 'ar_equipment', 'category_id')['counts']['location_disagreement_review'])->toBe(1)
        ->and(DB::table('ar_equipment')->count())->toBe(6);
});

it('preserves qualified schema lookup and rejects ambiguous normalized schemas', function () {
    $guard = app(ProtectionManifest::class);
    $schema = $guard->schema();
    expect(array_keys($schema))->toContain('public.ar_equipment');
    $schema['other.ar_equipment'] = $schema['public.ar_equipment'];
    expect($guard->violations(config('locations.protection'), $schema))->toContain('Ambiguous normalized schema table: ar_equipment.');
});

it('rejects missing parent columns even for generic logical relationships', function () {
    config(['locations.protection.tables.ar_equipment.parents.1.kind' => 'logical']);
    DB::getSchemaBuilder()->table('ar_categories', fn (Blueprint $table) => $table->renameColumn('id', 'private_parent_key'));
    expect(fn () => app(LocationRelationInventory::class)->report())->toThrow(RuntimeException::class, LocationRelationInventory::FAILURE);
});

it('does not equijoin conditional logical identifiers or infer a direct owner from them', function () {
    locationRelationFixture();
    config([
        'locations.protection.tables.ar_equipment.parents.0.kind' => 'conditional_logical',
        'locations.protection.tables.ar_equipment.parents.1.kind' => 'conditional_logical',
    ]);
    $report = app(LocationRelationInventory::class)->report();
    expect(locationRelationEdge($report, 'ar_equipment', 'category_id'))->toMatchArray([
        'availability' => 'conditional_relation_not_inspected',
        'ownership_comparison' => 'conditional_semantics_not_verified',
        'counts' => null,
    ])
        ->and(collect($report['tables'])->firstWhere('table', 'ar_equipment')['location_column'])->toBeNull();
});

it('limits historical references to structural review without comparing ownership', function () {
    locationRelationFixture();
    config(['locations.protection.tables.ar_equipment.parents.1.kind' => 'historical']);
    $edge = locationRelationEdge(app(LocationRelationInventory::class)->report(), 'ar_equipment', 'category_id');
    expect($edge['ownership_comparison'])->toBe('historical_structure_only_not_verified')
        ->and($edge['counts'])->toBe([
            'null_reference' => 1, 'missing_parent' => 0, 'parent_null_ownership' => null,
            'child_null_ownership' => null, 'location_disagreement_review' => null,
        ]);
});

it('uses the exact location edge when another parent shares its child column', function () {
    locationRelationFixture();
    $parents = config('locations.protection.tables.ar_equipment.parents');
    array_unshift($parents, [
        'child_column' => 'ext_location_id', 'table' => 'ar_categories', 'column' => 'sort_order',
        'kind' => 'logical', 'source_null_rule' => 'retain', 'optional_column' => false, 'absence_reason' => null,
    ]);
    config(['locations.protection.tables.ar_equipment.parents' => $parents]);
    $table = collect(app(LocationRelationInventory::class)->report()['tables'])->firstWhere('table', 'ar_equipment');
    expect($table['source_null_rule'])->toBe('shared')
        ->and($table['counts'])->toBe(['null_ownership' => 1, 'missing_location' => 0, 'inactive_location' => 1]);
});
