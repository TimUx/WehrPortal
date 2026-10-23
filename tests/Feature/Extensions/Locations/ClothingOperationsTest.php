<?php

use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingOperations;
use Extensions\Locations\Services\LocationDataInventory;
use Extensions\Locations\Services\LocationModuleRoles;
use Extensions\Locations\Services\ProtectionManifest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.clothing_stock_enabled' => false, 'locations.clothing_personal_enabled' => false]);
    Module::where('key', 'kleiderkammer')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    $this->admin = User::factory()->create(['is_admin' => true]);
    $this->a = Location::create(['name' => 'Wache Nord', 'slug' => 'operation-nord']);
    $this->b = Location::create(['name' => 'Wache Süd', 'slug' => 'operation-sued']);
    $this->worker = User::factory()->create(['ext_location_id' => $this->a->id]);
    DB::table('x_location_user')->insert(['location_id' => $this->b->id, 'user_id' => $this->worker->id, 'role' => 'member']);
    $this->type = DB::table('kk_item_types')->insertGetId(['name' => 'Handschuhe', 'is_tracked' => false]);
    $this->from = DB::table('kk_stock')->insertGetId(['item_type_id' => $this->type, 'quantity' => 12, 'ext_location_id' => $this->a->id]);
    $this->to = DB::table('kk_stock')->insertGetId(['item_type_id' => $this->type, 'quantity' => 5, 'ext_location_id' => $this->b->id]);
    $this->grants = [];
    foreach ([$this->a, $this->b] as $location) {
        $this->grants[] = app(LocationModuleRoles::class)->grant($this->admin, $location->id, $this->worker->id,
            'kleiderkammer', 'bestand', 'Zentrale Bestandsverantwortung', now()->addHour()->toIso8601String());
    }
    $this->issue = DB::table('kk_issued_items')->insertGetId([
        'user_id' => $this->worker->id, 'item_type_id' => $this->type, 'quantity' => 2,
        'ext_source_stock_id' => $this->from, 'issued_at' => now()->subDays(3), 'returned_at' => now()->subDay(),
    ]);
    config(['locations.clothing_stock_enabled' => true]);
    $this->operations = app(ClothingOperations::class);
    $this->transfer = fn (?User $actor = null, int $quantity = 3, ?string $key = null) => $this->operations->transfer(
        $actor ?? $this->worker, $this->from, $this->to, $quantity, 'Umlagerung nach Inventur', $key ?? (string) Str::uuid());
    $this->preview = fn (?User $actor = null) => $this->operations->correctHistory(
        $actor ?? $this->admin, $this->issue, $this->to, 'Belegte Herkunft', 'Inventurbeleg TEST-42');
    $this->apply = fn (string $hash, bool $confirmed = true) => $this->operations->correctHistory(
        $this->admin, $this->issue, $this->to, 'Belegte Herkunft', 'Inventurbeleg TEST-42', true, $hash, $confirmed);
});

it('transfers only quantities and records immutable before balances without personal access', function () {
    $original = (array) DB::table('kk_issued_items')->find($this->issue);
    $id = ($this->transfer)();
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(9)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(8)
        ->and(DB::table('x_clothing_stock_transfers')->find($id))->toMatchObject([
            'quantity' => 3, 'from_before' => 12, 'to_before' => 5, 'actor_id' => $this->worker->id,
            'from_location_id' => $this->a->id, 'to_location_id' => $this->b->id,
        ])
        ->and((array) DB::table('kk_issued_items')->find($this->issue))->toBe($original)
        ->and($this->worker->canManageKleiderkammer())->toBeFalse();
    expect(fn () => $this->operations->history($this->worker, $this->issue))->toThrow(HttpException::class);
});

it('reauthorizes both transfer endpoints and held actor state', function (string $change) {
    match ($change) {
        'source_revoke' => app(LocationModuleRoles::class)->revoke($this->admin, $this->grants[0]->id, $this->a->id, 'Beendet'),
        'target_revoke' => app(LocationModuleRoles::class)->revoke($this->admin, $this->grants[1]->id, $this->b->id, 'Beendet'),
        'expiry' => $this->travel(2)->hours(),
        'membership' => DB::table('x_location_user')->where('user_id', $this->worker->id)->delete(),
        'inactive_actor' => DB::table('users')->where('id', $this->worker->id)->update(['is_active' => false]),
        'inactive_module' => DB::table('modules')->where('key', 'kleiderkammer')->update(['is_active' => false]),
        'disabled_adapter' => config(['locations.clothing_stock_enabled' => false]),
        'disabled_locations' => config(['locations.enabled' => false]),
        'null_target' => DB::table('kk_stock')->where('id', $this->to)->update(['ext_location_id' => null]),
    };
    expect(fn () => ($this->transfer)())->toThrow(HttpException::class);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12)
        ->and(DB::table('x_clothing_stock_transfers')->count())->toBe(0);
})->with(['source_revoke', 'target_revoke', 'expiry', 'membership', 'inactive_actor', 'inactive_module', 'disabled_adapter', 'disabled_locations', 'null_target']);

it('does not substitute location administration legacy or personal roles for explicit stock grants', function (string $role) {
    foreach ($this->grants as $grant) {
        app(LocationModuleRoles::class)->revoke($this->admin, $grant->id, $grant->location_id, 'Rolle beendet');
    }
    match ($role) {
        'legacy' => $this->worker->moduleAccess()->create(['module' => 'kleiderkammer', 'role' => 'kleiderwart']),
        'location_admin' => DB::table('x_location_user')->where('user_id', $this->worker->id)->update(['role' => 'admin']),
        'personal' => app(LocationModuleRoles::class)->grant($this->admin, $this->a->id, $this->worker->id, 'kleiderkammer', 'kleiderwart', 'Persönlich'),
    };
    expect(fn () => ($this->transfer)())->toThrow(HttpException::class);
})->with(['legacy', 'location_admin', 'personal']);

it('allows a fresh global admin but not a stale admin flag', function () {
    ($this->transfer)($this->admin);
    DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    expect(fn () => ($this->transfer)($this->admin))->toThrow(HttpException::class);
});

it('refuses invalid transfer quantities and rolls back fully', function (int $quantity) {
    expect(fn () => ($this->transfer)(quantity: $quantity))->toThrow(ValidationException::class);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(5)
        ->and(DB::table('x_clothing_stock_transfers')->count())->toBe(0);
})->with([0, -1, 13, 2147483648]);

it('rejects mismatched catalogs inactive null same or overflowing target accounts even for admins', function (string $change) {
    match ($change) {
        'catalog' => DB::table('kk_stock')->where('id', $this->to)->update(['item_type_id' => DB::table('kk_item_types')->insertGetId(['name' => 'Gürtel'])]),
        'source_inactive' => DB::table('x_locations')->where('id', $this->a->id)->update(['is_active' => false]),
        'target_inactive' => DB::table('x_locations')->where('id', $this->b->id)->update(['is_active' => false]),
        'null' => DB::table('kk_stock')->where('id', $this->to)->update(['ext_location_id' => null]),
        'overflow' => DB::table('kk_stock')->where('id', $this->to)->update(['quantity' => 2147483647]),
        'same' => $this->to = $this->from,
    };
    expect(fn () => ($this->transfer)($this->admin))->toThrow(ValidationException::class);
    expect(DB::table('x_clothing_stock_transfers')->count())->toBe(0);
})->with(['catalog', 'source_inactive', 'target_inactive', 'null', 'overflow', 'same']);

it('requires a reason and a fresh operation key and prevents replay', function () {
    expect(fn () => $this->operations->transfer($this->worker, $this->from, $this->to, 1, ' ', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    $key = (string) Str::uuid();
    ($this->transfer)(key: $key);
    expect(fn () => ($this->transfer)(key: $key))->toThrow(ValidationException::class);
    expect(DB::table('x_clothing_stock_transfers')->count())->toBe(1)
        ->and(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(9);
});

it('corrects transfers only with an exact fully authorized counterbooking once', function () {
    $id = ($this->transfer)();
    expect(fn () => $this->operations->transfer($this->worker, $this->to, $this->from, 2, 'Korrektur', (string) Str::uuid(), $id))
        ->toThrow(ValidationException::class);
    $counter = $this->operations->transfer($this->worker, $this->to, $this->from, 3, 'Korrektur', (string) Str::uuid(), $id);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(5)
        ->and(DB::table('x_clothing_stock_transfers')->where('id', $counter)->value('reverses_id'))->toBe($id);
    expect(fn () => $this->operations->transfer($this->worker, $this->to, $this->from, 3, 'Nochmals', (string) Str::uuid(), $id))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->operations->transfer($this->worker, $this->from, $this->to, 3, 'Undo Undo', (string) Str::uuid(), $counter))
        ->toThrow(ValidationException::class);
});

it('checks current stock and grants again for counterbookings', function (string $change) {
    $id = ($this->transfer)();
    if ($change === 'stock') {
        DB::table('kk_stock')->where('id', $this->to)->update(['quantity' => 0]);
    } else {
        app(LocationModuleRoles::class)->revoke($this->admin, $this->grants[1]->id, $this->b->id, 'Entzogen');
    }
    expect(fn () => $this->operations->transfer($this->worker, $this->to, $this->from, 3, 'Korrektur', (string) Str::uuid(), $id))
        ->toThrow($change === 'stock' ? ValidationException::class : HttpException::class);
    expect(DB::table('x_clothing_stock_transfers')->count())->toBe(1);
})->with(['stock', 'grant']);

it('rolls back stock journal and activity writes if the second account update fails', function () {
    DB::unprepared("CREATE OR REPLACE FUNCTION x_test_transfer_failure() RETURNS trigger AS $$ BEGIN IF NEW.id = {$this->to} THEN RAISE EXCEPTION 'test failure'; END IF; RETURN NEW; END; $$ LANGUAGE plpgsql");
    DB::statement('CREATE TRIGGER x_test_transfer_failure BEFORE UPDATE ON kk_stock FOR EACH ROW EXECUTE FUNCTION x_test_transfer_failure()');
    $logs = DB::table('activity_logs')->count();
    expect(fn () => ($this->transfer)())->toThrow(QueryException::class);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(5)
        ->and(DB::table('x_clothing_stock_transfers')->count())->toBe(0)
        ->and(DB::table('activity_logs')->count())->toBe($logs);
});

it('previews and appends reviewed historical assertions without changing any operational originals', function () {
    $original = (array) DB::table('kk_issued_items')->find($this->issue);
    $stock = DB::table('kk_stock')->orderBy('id')->get()->toArray();
    $preview = ($this->preview)();
    expect($preview['correction_id'])->toBeNull()->and(DB::table('x_clothing_history_corrections')->count())->toBe(0);
    $result = ($this->apply)($preview['hash']);
    expect($result['correction_id'])->toBeInt()->and($result['operational_source_unchanged'])->toBeTrue()
        ->and((array) DB::table('kk_issued_items')->find($this->issue))->toBe($original)
        ->and(DB::table('kk_stock')->orderBy('id')->get()->toArray())->toEqual($stock)
        ->and($this->operations->history($this->admin, $this->issue)['journal'])->toHaveCount(1)
        ->and($preview['historical_report']['operational_source_stock_id'])->toBe($this->from)
        ->and($preview['historical_report']['reviewed_source_stock_id'])->toBeNull()
        ->and($result['historical_report']['reviewed_source_stock_id'])->toBe($this->to);
    $row = DB::table('x_clothing_history_corrections')->find($result['correction_id']);
    expect(json_decode($row->original, true)['ext_source_stock_id'])->toBe($this->from)
        ->and($row->evidence)->toBe('Inventurbeleg TEST-42');
    expect(fn () => ($this->apply)($preview['hash']))->toThrow(ValidationException::class);
});

it('requires live global admin status for historical preview apply and journal reads', function (string $change) {
    $hash = ($this->preview)()['hash'];
    $actor = $this->admin;
    match ($change) {
        'demoted' => DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]),
        'inactive' => DB::table('users')->where('id', $actor->id)->update(['is_active' => false]),
        'worker' => $actor = $this->worker,
    };
    expect(fn () => ($this->preview)($actor))->toThrow(HttpException::class);
    expect(fn () => $this->operations->correctHistory($actor, $this->issue, $this->to, 'Belegte Herkunft', 'Inventurbeleg TEST-42', true, $hash, true))
        ->toThrow(HttpException::class);
    expect(fn () => $this->operations->history($actor, $this->issue))->toThrow(HttpException::class);
})->with(['demoted', 'inactive', 'worker']);

it('rejects unconfirmed stale or changed historical reviews', function (string $change) {
    $hash = ($this->preview)()['hash'];
    match ($change) {
        'issue' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['notes' => 'Geprüfter Originalstand geändert']),
        'stock' => DB::table('kk_stock')->where('id', $this->to)->update(['quantity' => 8]),
        'location' => DB::table('x_locations')->where('id', $this->b->id)->update(['name' => 'Anderer Name']),
        'hash' => $hash = str_repeat('0', 64),
        'unconfirmed' => null,
    };
    expect(fn () => ($this->apply)($hash, $change !== 'unconfirmed'))->toThrow(ValidationException::class);
    expect(DB::table('x_clothing_history_corrections')->count())->toBe(0);
})->with(['issue', 'stock', 'location', 'hash', 'unconfirmed']);

it('binds historical reviews to evidence reason and actor', function (string $change) {
    $hash = ($this->preview)()['hash'];
    $actor = $change === 'actor' ? User::factory()->create(['is_admin' => true]) : $this->admin;
    expect(fn () => $this->operations->correctHistory($actor, $this->issue, $this->to,
        $change === 'reason' ? 'Anderer Grund' : 'Belegte Herkunft',
        $change === 'evidence' ? 'Anderer Beleg' : 'Inventurbeleg TEST-42', true, $hash, true))
        ->toThrow(ValidationException::class);
})->with(['evidence', 'reason', 'actor']);

it('does not guess open deleted mismatched or already correct historical sources', function (string $change) {
    match ($change) {
        'open' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['returned_at' => null]),
        'deleted' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['deleted_at' => now()]),
        'catalog' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['item_type_id' => null]),
        'already_correct' => $this->to = $this->from,
    };
    expect(fn () => ($this->preview)())->toThrow(ValidationException::class);
})->with(['open', 'deleted', 'catalog', 'already_correct']);

it('preserves legacy null provenance and records successive corrections as an immutable overlay chain', function () {
    $legacy = DB::table('kk_issued_items')->insertGetId([
        'user_id' => $this->worker->id, 'item_type_id' => $this->type,
        'quantity' => 1, 'issued_at' => now()->subDays(2), 'returned_at' => now()->subDay(),
    ]);
    $first = $this->operations->correctHistory($this->admin, $legacy, $this->to, 'Beleg A', 'TEST-A');
    $first = $this->operations->correctHistory($this->admin, $legacy, $this->to, 'Beleg A', 'TEST-A', true, $first['hash'], true);
    $second = $this->operations->correctHistory($this->admin, $legacy, $this->from, 'Beleg B', 'TEST-B');
    $second = $this->operations->correctHistory($this->admin, $legacy, $this->from, 'Beleg B', 'TEST-B', true, $second['hash'], true);
    expect($second['previous_id'])->toBe($first['correction_id'])
        ->and(DB::table('kk_issued_items')->where('id', $legacy)->value('ext_source_stock_id'))->toBeNull()
        ->and(DB::table('x_clothing_history_corrections')->count())->toBe(2);
    $report = $this->operations->history($this->admin, $legacy);
    expect($report['operational_source_stock_id'])->toBeNull()
        ->and($report['reviewed_source_stock_id'])->toBe($this->from)
        ->and($report['reviewed_location_id'])->toBe($this->a->id)
        ->and($report['latest_correction_id'])->toBe($second['correction_id'])
        ->and($report['journal'])->toHaveCount(2);
});

it('reports reviewed sources for admins without replaying already completed returns or pretending to balance stock', function () {
    $result = ($this->apply)(($this->preview)()['hash']);
    $report = $this->operations->history($this->admin, $this->issue);
    expect($report['operational_source_stock_id'])->toBe($this->from)
        ->and($report['reviewed_source_stock_id'])->toBe($this->to)
        ->and($report['reviewed_location_id'])->toBe($this->b->id)
        ->and($report['latest_correction_id'])->toBe($result['correction_id'])
        ->and($report['return_routing'])->toBe('unchanged_original_source_or_legacy_rules')
        ->and($report['inventory_balancing'])->toBe('not_performed_separate_authorized_stock_counterbooking_required');
    expect(fn () => app(IssuingService::class)->return(IssuedItem::withoutGlobalScopes()->findOrFail($this->issue), actor: $this->admin))
        ->toThrow(DomainException::class);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(5)
        ->and(DB::table('x_clothing_stock_transfers')->count())->toBe(0);
});

it('keeps outstanding returns source bound after stock transfers', function () {
    $open = DB::table('kk_issued_items')->insertGetId([
        'user_id' => $this->worker->id, 'item_type_id' => $this->type, 'quantity' => 2,
        'ext_source_stock_id' => $this->from, 'issued_at' => now(),
    ]);
    ($this->transfer)();
    app(IssuingService::class)->return(IssuedItem::withoutGlobalScopes()->findOrFail($open), actor: $this->admin);
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(11)
        ->and(DB::table('kk_stock')->where('id', $this->to)->value('quantity'))->toBe(8);
});

it('enforces journal immutability for update delete truncate and unsafe rollback', function (string $journal, string $action) {
    if ($journal === 'x_clothing_stock_transfers') {
        ($this->transfer)();
    } else {
        ($this->apply)(($this->preview)()['hash']);
    }
    if ($action === 'rollback') {
        $migration = require base_path('extensions/Locations/database/migrations/2026_12_30_000000_create_clothing_operation_journals.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'refusing');
    } else {
        expect(fn () => DB::transaction(fn () => match ($action) {
            'update' => DB::table($journal)->update(['reason' => 'Rewrite']),
            'delete' => DB::table($journal)->delete(),
            'truncate' => DB::statement("TRUNCATE {$journal} CASCADE"),
        }))->toThrow(QueryException::class, 'append-only');
    }
    expect(DB::table($journal)->count())->toBe(1);
})->with(['x_clothing_stock_transfers', 'x_clothing_history_corrections'])->with(['update', 'delete', 'truncate', 'rollback']);

it('does not transfer immutable operator identities through member removal', function () {
    ($this->transfer)();
    expect(fn () => app(LocationModuleRoles::class)->assertMemberDeletable($this->worker))->toThrow(ValidationException::class);
    expect(app(MemberRemoval::class)->references()->pluck('table')->all())->not->toContain('x_clothing_stock_transfers', 'x_clothing_history_corrections');
});

it('classifies both journals and inventories only counts without logging private correction evidence', function () {
    ($this->transfer)();
    ($this->apply)(($this->preview)()['hash']);
    expect(app(ProtectionManifest::class)->violations(config('locations.protection')))->toBe([]);
    $inventory = collect(app(LocationDataInventory::class)->rows());
    expect($inventory->firstWhere('table', 'x_clothing_stock_transfers'))->toMatchArray([
        'data_class' => 'audit', 'rows' => 1, 'location_column' => 'from_location_id',
    ])
        ->and($inventory->firstWhere('table', 'x_clothing_history_corrections'))->toMatchArray([
            'data_class' => 'personnel', 'rows' => 1, 'location_column' => 'reviewed_location_id',
        ])
        ->and($inventory->toJson())->not->toContain('Inventurbeleg TEST-42', 'Belegte Herkunft')
        ->and(DB::table('activity_logs')->get()->toJson())->not->toContain('Inventurbeleg TEST-42', 'Belegte Herkunft');
});

it('refuses operations before incomplete journal schema can be used', function () {
    DB::statement('ALTER TABLE x_clothing_history_corrections DROP COLUMN evidence CASCADE');
    expect(fn () => ($this->transfer)())->toThrow(ValidationException::class, 'Migrationen');
    expect(fn () => ($this->preview)())->toThrow(ValidationException::class, 'Migrationen');
    expect(DB::table('kk_stock')->where('id', $this->from)->value('quantity'))->toBe(12);
});

it('provides usable noninteractive transfer and historical review commands without exposing private history to stock operators', function () {
    $this->artisan('locations:transfer-clothing-stock', [
        'from' => (string) $this->from, 'to' => (string) $this->to, 'quantity' => '2',
        '--by' => (string) $this->worker->id, '--reason' => 'Umlagerung', '--key' => (string) Str::uuid(),
    ])->assertSuccessful();
    $this->artisan('locations:correct-clothing-history', [
        'issuance' => (string) $this->issue, 'source' => (string) $this->to, '--by' => (string) $this->worker->id,
        '--reason' => 'Prüfung', '--evidence' => 'TEST',
    ])->assertFailed();
    $this->artisan('locations:correct-clothing-history', [
        'issuance' => (string) $this->issue, 'source' => (string) $this->to, '--by' => (string) $this->admin->id,
        '--reason' => 'Belegte Herkunft', '--evidence' => 'Inventurbeleg TEST-42',
    ])->expectsOutputToContain('"dry_run":true')->assertSuccessful();
    $hash = ($this->preview)()['hash'];
    $this->artisan('locations:correct-clothing-history', [
        'issuance' => (string) $this->issue, 'source' => (string) $this->to, '--by' => (string) $this->admin->id,
        '--reason' => 'Belegte Herkunft', '--evidence' => 'Inventurbeleg TEST-42', '--apply' => true, '--expect' => $hash, '--confirmed' => true,
    ])->assertSuccessful();
    $this->artisan('locations:correct-clothing-history', [
        'issuance' => (string) $this->issue, '--by' => (string) $this->admin->id, '--history' => true,
    ])->expectsOutputToContain('"historical_report":{')->assertSuccessful();
});
