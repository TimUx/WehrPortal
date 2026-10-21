<?php

use App\Models\User;
use Extensions\Locations\Console\AuditClothingCommand;
use Extensions\Locations\LocationsServiceProvider;
use Extensions\Locations\Services\ClothingReadinessReport;
use Extensions\Locations\Services\LocationModuleRoles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Modules\Kleiderkammer\src\Models\Item;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Services\IssuingService;

// The audit deliberately opens an independent connection; fixtures must be committed.
beforeEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());
afterEach(fn () => $this->artisan('migrate:fresh')->assertSuccessful());

function clothingAuditFixture(): array
{
    $north = DB::table('x_locations')->insertGetId(['name' => 'Wache Nord', 'slug' => 'audit-north']);
    $south = DB::table('x_locations')->insertGetId(['name' => 'Wache Süd', 'slug' => 'audit-south']);
    $inactive = DB::table('x_locations')->insertGetId(['name' => 'Alte Wache', 'slug' => 'audit-inactive', 'is_active' => false]);
    $users = [];
    foreach (['primary', 'multi', 'pivot', 'none', 'admin', 'inactive'] as $key) {
        $user = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => $key === 'admin']);
        DB::table('users')->where('id', $user->id)->update([
            'ext_location_id' => match ($key) {
                'pivot', 'none' => null,
                'inactive' => $inactive,
                default => $north,
            },
        ]);
        $users[$key] = $user->id;
    }
    DB::table('x_location_user')->insert([
        ['location_id' => $south, 'user_id' => $users['multi'], 'role' => 'member'],
        ['location_id' => $north, 'user_id' => $users['multi'], 'role' => 'member'],
        ['location_id' => $south, 'user_id' => $users['pivot'], 'role' => 'member'],
    ]);
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Jacke']);
    $size = DB::table('kk_sizes')->insertGetId(['item_type_id' => $type, 'name' => 'M']);
    $items = [];
    foreach (['north' => $north, 'south' => $south, 'null' => null, 'inactive' => $inactive] as $key => $location) {
        $items[$key] = DB::table('kk_items')->insertGetId(['item_type_id' => $type, 'code' => 'AUDIT-'.$key, 'ext_location_id' => $location]);
    }
    $issues = [];
    foreach ($users as $key => $user) {
        $issues[$key] = DB::table('kk_issued_items')->insertGetId([
            'user_id' => $user, 'item_id' => $items['south'], 'issued_at' => now(),
        ]);
    }
    foreach (['null', 'inactive'] as $key) {
        $issues[$key] = DB::table('kk_issued_items')->insertGetId([
            'user_id' => $users['primary'], 'item_id' => $items[$key], 'issued_at' => now(),
        ]);
        DB::table('kk_item_treatments')->insert(['item_id' => $items[$key], 'type' => 'washing', 'treated_at' => now()]);
    }
    DB::table('kk_issued_items')->insert([
        ['user_id' => $users['primary'], 'item_id' => $items['north'], 'issued_at' => now(), 'returned_at' => now(), 'deleted_at' => null],
        ['user_id' => $users['primary'], 'item_id' => $items['south'], 'issued_at' => now(), 'returned_at' => now(), 'deleted_at' => now()],
    ]);
    $issues['quantity'] = DB::table('kk_issued_items')->insertGetId(['user_id' => $users['primary'], 'item_type_id' => $type, 'quantity' => 7, 'issued_at' => now()]);
    DB::table('kk_stock')->insert(['item_type_id' => $type, 'quantity' => 12]);
    DB::table('kk_member_sizes')->insert(['user_id' => $users['multi'], 'item_type_id' => $type, 'size_id' => $size]);
    foreach ([
        [$users['primary'], $issues['multi']],
        [$users['multi'], $issues['multi']],
        [$users['none'], null],
        [$users['primary'], $issues['null']],
        [$users['primary'], $issues['inactive']],
        [$users['primary'], $issues['quantity']],
    ] as [$user, $issue]) {
        DB::table('kk_equipment_messages')->insert([
            'user_id' => $user, 'issued_item_id' => $issue, 'item_label' => 'Vertraulicher Inhalt', 'body' => 'Niemals ausgeben',
        ]);
    }

    return compact('north', 'south', 'inactive', 'users', 'items', 'type');
}

it('reports aggregate hybrid readiness without guessing personal or quantity stock ownership', function () {
    clothingAuditFixture();
    $report = app(ClothingReadinessReport::class)->report();
    $counts = array_map(fn (array $check) => $check['counts'], $report['checks']);

    expect($report['read_only'])->toBeTrue()
        ->and($report['enforcement_verified'])->toBeFalse()
        ->and($report['automatic_target_inference'])->toBeFalse()
        ->and($counts['items'])->toBe(['null_location' => 1, 'inactive_location' => 1])
        ->and($counts['issuances'])->toBe([
            'quantity_or_unlinked_unresolved' => 1,
            'item_null_location' => 1,
            'item_inactive_location' => 1,
            'membership_needs_review' => 5,
            'open_membership_needs_review' => 4,
        ])
        ->and($counts['messages']['user_issuance_mismatch_needs_review'])->toBe(1)
        ->and($counts['messages']['membership_needs_review'])->toBe(1)
        ->and($counts['messages']['without_issuance_unresolved'])->toBe(1)
        ->and($counts['messages']['linked_quantity_unresolved'])->toBe(1)
        ->and($counts['messages']['item_null_location'])->toBe(1)
        ->and($counts['messages']['item_inactive_location'])->toBe(1)
        ->and($counts['treatments'])->toBe(['parent_null_location' => 1, 'parent_inactive_location' => 1])
        ->and($counts['stock'])->toBe(['rows_unresolved' => 1, 'quantity_unresolved' => 12, 'null_location' => 1, 'assigned' => 0, 'inactive_location' => 0, 'quantity_unassigned' => 12])
        ->and($counts['personal_owner_candidates'])->toBe([
            'owners_unresolved' => 6, 'primary_null' => 2, 'primary_inactive' => 1,
            'primary_active_potential' => 3, 'no_active_membership' => 2,
            'single_active_membership_potential' => 3, 'multiple_active_memberships_potential' => 1,
        ])
        ->and(collect($report['tables'])->pluck('classification', 'table')->all())->toBe([
            'kk_item_categories' => 'catalog', 'kk_item_types' => 'catalog', 'kk_sizes' => 'catalog',
            'kk_item_groups' => 'catalog', 'kk_items' => 'direct_item', 'kk_stock' => 'unresolved_quantity_stock',
            'kk_issued_items' => 'user_and_optional_item', 'kk_member_sizes' => 'user',
            'kk_equipment_messages' => 'user_and_optional_parent', 'kk_item_treatments' => 'parent_item',
        ]);
    $json = json_encode($report);
    expect($json)->not->toContain('Hanna', '@', 'Vertraulicher', 'Niemals', 'user_id', 'location_id');
});

it('is independent of caller authorization and selected location and changes no records or notifications', function () {
    $fixture = clothingAuditFixture();
    Notification::fake();
    Mail::fake();
    $tables = ['users', 'kk_items', 'kk_stock', 'kk_issued_items', 'kk_item_treatments', 'kk_member_sizes', 'kk_equipment_messages', 'activity_logs', 'notifications', 'mail_logs', 'x_location_user'];
    $snapshot = fn () => array_map(fn (string $table) => DB::table($table)->orderByRaw('1')->get()->toJson(), $tables);
    $before = $snapshot();
    $expected = app(ClothingReadinessReport::class)->report();
    $this->actingAs(User::withoutGlobalScopes()->findOrFail($fixture['users']['primary']));
    session(['ext_location_id' => $fixture['south']]);
    expect(Item::count())->toBe(2);
    expect(app(ClothingReadinessReport::class)->report())->toBe($expected);
    $this->actingAs(User::withoutGlobalScopes()->findOrFail($fixture['users']['admin']));
    expect(Item::count())->toBe(4)
        ->and(app(ClothingReadinessReport::class)->report())->toBe($expected)
        ->and($snapshot())->toBe($before);
    Notification::assertNothingSent();
    Mail::assertNothingSent();
});

it('reports assigned stock metadata without claiming resolved stock operations', function () {
    $fixture = clothingAuditFixture();
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe']);
    DB::table('kk_stock')->insert([
        ['item_type_id' => $type, 'quantity' => 4, 'ext_location_id' => $fixture['south'], 'size_id' => null],
        ['item_type_id' => $fixture['type'], 'quantity' => 2, 'ext_location_id' => $fixture['inactive'],
            'size_id' => DB::table('kk_sizes')->where('item_type_id', $fixture['type'])->value('id')],
    ]);
    $report = app(ClothingReadinessReport::class)->report();
    expect($report['checks']['stock']['counts'])->toBe([
        'rows_unresolved' => 3, 'quantity_unresolved' => 18, 'null_location' => 1,
        'assigned' => 2, 'inactive_location' => 1, 'quantity_unassigned' => 12,
    ])
        ->and($report['stock_global_uniqueness_preserved'])->toBeTrue()
        ->and($report['stock_operation_policy'])->toContain('unresolved_all_rows', 'issues_match_globally', 'source_bound_returns_restore_exact_source', 'legacy_unsourced_returns_match_globally', 'no_stock_query_adapter')
        ->and($report['enforcement_verified'])->toBeFalse();
    expect(Artisan::call('locations:audit-clothing'))->toBe(0)
        ->and(Artisan::output())->toContain('Ausgabe sucht weiterhin global', 'Neue Mengenausgaben mit Herkunft', 'alte Ausgaben ohne Herkunft', 'Alle Bestandszeilen sind operativ ungeklärt');
});

it('adds provenance counts without resolving stock or inferring historical sources', function () {
    $fixture = clothingAuditFixture();
    $service = app(IssuingService::class);
    $issued = $service->issueStock(User::withoutGlobalScopes()->findOrFail($fixture['users']['primary']), ItemType::findOrFail($fixture['type']), null, 2);
    $service->return($issued);
    $issued->delete();
    $report = app(ClothingReadinessReport::class)->report();
    expect($report['checks']['issuance_provenance'])->toBe([
        'availability' => 'available',
        'counts' => ['source_bound' => 1, 'unsourced_bulk_or_unlinked' => 1],
    ])
        ->and($report['checks']['stock']['counts']['rows_unresolved'])->toBe(1)
        ->and($report['enforcement_verified'])->toBeFalse();
});

it('reports optional provenance as unavailable on older schema instead of a false zero', function () {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_26_000000_record_clothing_issuance_stock_source.php');
    DB::transaction(fn () => $migration->down());
    $report = app(ClothingReadinessReport::class)->report();
    expect($report['checks']['issuance_provenance'])->toBe(['availability' => 'unavailable', 'counts' => null])
        ->and($report['checks']['stock']['counts']['rows_unresolved'])->toBe(0);
});

it('reports empty clothing tables as available zero counts and exposes only a read only CLI', function () {
    expect(Artisan::call('locations:audit-clothing', ['--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(collect($report['tables'])->pluck('rows')->unique()->all())->toBe([0])
        ->and(collect($report['tables'])->pluck('availability')->unique()->all())->toBe(['available'])
        ->and($report['checks']['personal_owner_candidates']['counts']['owners_unresolved'])->toBe(0)
        ->and(array_keys((new AuditClothingCommand)->getDefinition()->getOptions()))->toBe(['json']);
    expect(Artisan::call('locations:audit-clothing'))->toBe(0)
        ->and(Artisan::output())->toContain('Nur lesend', 'kein bestätigter Verstoß');
});

it('marks optional missing leaf tables unavailable rather than verified zero', function () {
    DB::getSchemaBuilder()->drop('kk_equipment_messages');
    DB::getSchemaBuilder()->drop('kk_member_sizes');
    DB::getSchemaBuilder()->drop('kk_item_treatments');
    $report = app(ClothingReadinessReport::class)->report();
    foreach (['kk_equipment_messages', 'kk_member_sizes', 'kk_item_treatments'] as $table) {
        expect(collect($report['tables'])->firstWhere('table', $table))->toMatchArray(['availability' => 'unavailable', 'rows' => null]);
    }
    expect($report['checks']['messages'])->toBe(['availability' => 'unavailable', 'counts' => null])
        ->and($report['checks']['treatments'])->toBe(['availability' => 'unavailable', 'counts' => null])
        ->and($report['personal_owner_sources'])->toBe(['kk_issued_items']);
});

it('fails closed without leaking malformed manifests or SQL details', function () {
    config(['locations.protection.tables.kk_items' => ['secret' => 'Never expose this payload']]);
    expect(Artisan::call('locations:audit-clothing', ['--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true))->toBe([
        'read_only' => true, 'enforcement_verified' => false, 'error' => 'clothing_readiness_unavailable',
    ]);
    expect(Artisan::call('locations:audit-clothing'))->toBe(1)
        ->and(Artisan::output())->not->toContain('payload', 'SQLSTATE', 'select ');
});

it('rejects missing required columns safely', function () {
    DB::getSchemaBuilder()->table('kk_issued_items', fn (Blueprint $table) => $table->renameColumn('returned_at', 'old_returned_at'));
    expect(fn () => app(ClothingReadinessReport::class)->report())->toThrow(RuntimeException::class, ClothingReadinessReport::FAILURE);
});

it('uses a repeatable read read only snapshot without changing an existing caller transaction', function () {
    $mode = null;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$mode) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $mode = $query->connection->selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        }
    });
    DB::beginTransaction();
    try {
        $before = DB::selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only");
        app(ClothingReadinessReport::class)->report();
        expect($mode->isolation)->toBe('repeatable read')
            ->and($mode->read_only)->toBe('on')
            ->and(DB::transactionLevel())->toBe(1)
            ->and(DB::selectOne("SELECT current_setting('transaction_isolation') AS isolation, current_setting('transaction_read_only') AS read_only"))->toEqual($before);
    } finally {
        DB::rollBack();
    }
});

it('honors the database URL instead of conflicting fallback connection settings', function () {
    $key = 'database.connections.'.config('database.default');
    $original = config($key);
    $resolved = DB::connection()->getConfig();
    $url = 'postgresql://'.rawurlencode($resolved['username']).':'.rawurlencode($resolved['password'])
        .'@'.$resolved['host'].':'.$resolved['port'].'/'.rawurlencode($resolved['database']);
    config([$key => [
        ...$original,
        'url' => $url,
        'database' => 'clothing_audit_wrong_fallback',
        'username' => 'wrong_fallback',
        'password' => 'wrong_fallback',
    ]]);
    $actualDatabase = null;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$actualDatabase) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $actualDatabase = $query->connection->selectOne('SELECT current_database() AS name')->name;
        }
    });
    try {
        $report = app(ClothingReadinessReport::class)->report();
        expect($actualDatabase)->toBe($resolved['database'])
            ->and($report['snapshot'])->toBe('postgresql_repeatable_read_read_only');
    } finally {
        config([$key => $original]);
    }
});

it('enforces database read only mode and sanitizes database failures', function () {
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) {
        if ($query->sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY') {
            $query->connection->table('kk_items')->whereRaw('false')->update(['notes' => 'forbidden write']);
        }
    });
    expect(Artisan::call('locations:audit-clothing', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->not->toContain('SQLSTATE', 'forbidden write', 'update ');
});

it('holds a consistent snapshot when a concurrent connection commits new items', function () {
    $type = DB::table('kk_item_types')->insertGetId(['name' => 'Jacke']);
    $inserted = false;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use ($type, &$inserted) {
        if (! $inserted && str_contains($query->sql, 'count(*) as aggregate from "kk_items"')) {
            $inserted = true;
            DB::table('kk_items')->insert(['item_type_id' => $type, 'code' => 'CONCURRENT', 'ext_location_id' => null]);
        }
    });
    $report = app(ClothingReadinessReport::class)->report();
    expect($inserted)->toBeTrue()
        ->and(collect($report['tables'])->firstWhere('table', 'kk_items')['rows'])->toBe(0)
        ->and($report['checks']['items']['counts']['null_location'])->toBe(0)
        ->and(DB::table('kk_items')->count())->toBe(1);
});

it('does not register the command when the Locations provider is disabled', function () {
    config(['locations.enabled' => false]);
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('make')->with(LocationModuleRoles::class)->andReturn(app(LocationModuleRoles::class));
    $app->shouldNotReceive('runningInConsole');
    (new LocationsServiceProvider($app))->boot();
    expect(fn () => app(ClothingReadinessReport::class)->report())->toThrow(RuntimeException::class, ClothingReadinessReport::FAILURE);
});
