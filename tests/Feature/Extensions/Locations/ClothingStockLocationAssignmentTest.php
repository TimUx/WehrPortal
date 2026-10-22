<?php

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingStockLocationAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Kleiderkammer\src\Models\ItemGroup;
use Modules\Kleiderkammer\src\Models\ItemType;
use Modules\Kleiderkammer\src\Models\Size;
use Modules\Kleiderkammer\src\Services\IssuingService;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->target = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'stock-south']);
    $this->other = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'stock-north']);
    $this->admin = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => true]);
    $this->recipient = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->type = DB::table('kk_item_types')->insertGetId(['name' => 'Private Handschuhe', 'is_tracked' => false]);
    $this->size = DB::table('kk_sizes')->insertGetId(['item_type_id' => $this->type, 'name' => 'Privat M']);
    $this->group = DB::table('kk_item_groups')->insertGetId(['item_type_id' => $this->type, 'name' => 'Private Gruppe']);
    $this->stock = DB::table('kk_stock')->insertGetId([
        'item_type_id' => $this->type, 'size_id' => $this->size, 'group_id' => $this->group,
        'quantity' => 12, 'created_at' => now()->subYear(), 'updated_at' => now()->subMonth(),
    ]);
    $this->issue = DB::table('kk_issued_items')->insertGetId([
        'item_type_id' => $this->type, 'size_id' => $this->size, 'group_id' => $this->group,
        'user_id' => $this->recipient->id, 'issued_at' => now()->subYear(), 'returned_at' => now(),
        'notes' => 'Private Ausgabe', 'quantity' => 3,
    ]);
    $this->deletedIssue = DB::table('kk_issued_items')->insertGetId([
        'item_type_id' => $this->type, 'size_id' => $this->size, 'group_id' => $this->group,
        'user_id' => $this->recipient->id, 'issued_at' => now(), 'deleted_at' => now(),
        'notes' => 'Private Historie', 'quantity' => 2,
    ]);
    $this->message = DB::table('kk_equipment_messages')->insertGetId([
        'user_id' => $this->recipient->id, 'issued_item_id' => $this->deletedIssue,
        'item_label' => 'Private Meldung', 'body' => 'Niemals veröffentlichen',
    ]);
    $this->service = app(ClothingStockLocationAssignment::class);
    $this->plan = [['table' => 'kk_stock', 'id' => $this->stock, 'location_id' => $this->target->id, 'expected_location_id' => null]];
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $this->path = storage_path('framework/testing/clothing-stock-assignment-'.Str::uuid().'.json');
    file_put_contents($this->path, json_encode($this->plan, JSON_THROW_ON_ERROR));
    $this->reason = 'Ganze Bestandszeile und Historie fachlich geprüft';
    $this->preview = fn () => $this->service->run($this->path, by: $this->admin->id, reason: $this->reason);
    $this->apply = fn (?string $hash = null) => $this->service->run(
        $this->path, true, $this->admin->id, $this->reason, $hash ?? ($this->preview)()['hash'], true,
    );
    $this->migration = require base_path('extensions/Locations/database/migrations/2026_12_25_000000_link_clothing_stock_to_locations.php');
    $this->journalMigration = require base_path('extensions/Locations/database/migrations/2026_12_25_000001_allow_clothing_stock_location_assignments.php');
});

afterEach(function () {
    File::delete($this->path);
});

it('previews privately and only changes whole-row ownership with append-only approval', function () {
    $tables = ['kk_stock', 'kk_item_types', 'kk_sizes', 'kk_item_groups', 'kk_issued_items', 'kk_equipment_messages',
        'users', 'x_location_user', 'module_user', 'activity_logs', 'notifications', 'mail_logs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->orderByRaw('1')->get();
    }
    expect(Artisan::call('locations:assign-clothing-stock', ['plan' => $this->path]))->toBe(0);
    $output = Artisan::output();
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($result['dry_run'])->toBeTrue()
        ->and($result['batch_id'])->toBeNull()
        ->and($result['hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($this->service->run($this->path)['hash'])->toBe($result['hash'])
        ->and($result['rows'])->toBe([['table' => 'kk_stock', 'id' => $this->stock, 'location_id' => $this->target->id, 'status' => 'ready_history_requires_review']])
        ->and($output)->not->toContain('Private', 'Niemals', 'Hanna', 'Bernd');
    $hash = ($this->preview)()['hash'];
    expect(Artisan::call('locations:assign-clothing-stock', [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id,
        '--reason' => ' '.$this->reason.' ', '--expect' => $hash, '--backup-confirmed' => true,
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($tables as $table) {
        if ($table === 'kk_stock') {
            $before[$table][0]->ext_location_id = $this->target->id;
        }
        expect(DB::table($table)->orderByRaw('1')->get())->toEqual($before[$table]);
    }
    $journal = DB::table('x_location_data_assignments')->sole();
    expect($journal->target_table)->toBe('kk_stock')
        ->and($journal->target_id)->toBe($this->stock)
        ->and($journal->location_id)->toBe($this->target->id)
        ->and($journal->assigned_by)->toBe($this->admin->id)
        ->and($journal->reason)->toBe($this->reason)
        ->and($journal->state_hash)->toBe($hash)
        ->and($journal->batch_id)->toBe($result['batch_id']);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('rejects malformed oversized duplicate and non-null plans', function (string $case) {
    $row = $this->plan[0];
    $data = match ($case) {
        'duplicate' => [$row, $row],
        'table' => [[...$row, 'table' => 'kk_items']],
        'string' => [[...$row, 'id' => (string) $row['id']]],
        'float' => [[...$row, 'id' => 1.5]],
        'negative' => [[...$row, 'location_id' => -1]],
        'extra' => [[...$row, 'quantity' => 2]],
        'expected' => [[...$row, 'expected_location_id' => $this->other->id]],
        'missing_expected' => [['table' => 'kk_stock', 'id' => $this->stock, 'location_id' => $this->target->id]],
        'many' => array_fill(0, 501, $row),
        default => [],
    };
    file_put_contents($this->path, match ($case) {
        'invalid' => '[{', 'object' => '{}', 'oversize' => str_repeat(' ', 262145),
        default => json_encode($data),
    });
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['duplicate', 'table', 'string', 'float', 'negative', 'extra', 'expected', 'missing_expected', 'many', 'empty', 'invalid', 'object', 'oversize']);

it('requires explicit safeguards and active global administrator authority', function (string $case) {
    $hash = ($this->preview)()['hash'];
    if (in_array($case, ['member', 'inactive', 'left', 'local_admin'], true)) {
        DB::table('users')->where('id', $this->admin->id)->update(match ($case) {
            'inactive' => ['is_active' => false], 'left' => ['status' => 'left'], default => ['is_admin' => false],
        });
        if ($case === 'local_admin') {
            DB::table('x_location_user')->insert(['user_id' => $this->admin->id, 'location_id' => $this->target->id, 'role' => 'admin']);
        }
    }
    expect(fn () => $this->service->run(
        $this->path, true, $case === 'by' ? null : $this->admin->id,
        match ($case) {
            'reason' => ' ', 'long' => str_repeat('x', 501), default => $this->reason
        },
        match ($case) {
            'expect' => null, 'hash' => 'bad', default => $hash
        }, $case !== 'backup',
    ))->toThrow(RuntimeException::class)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['by', 'reason', 'long', 'expect', 'hash', 'backup', 'member', 'inactive', 'left', 'local_admin']);

it('binds the complete private relevant state actor and reason to approval', function (string $change) {
    $hash = ($this->preview)()['hash'];
    match ($change) {
        'quantity' => DB::table('kk_stock')->where('id', $this->stock)->update(['quantity' => 13]),
        'stock' => DB::table('kk_stock')->where('id', $this->stock)->update(['updated_at' => now()]),
        'type' => DB::table('kk_item_types')->where('id', $this->type)->update(['name' => 'Geändert']),
        'size' => DB::table('kk_sizes')->where('id', $this->size)->update(['name' => 'Geändert']),
        'group' => DB::table('kk_item_groups')->where('id', $this->group)->update(['name' => 'Geändert']),
        'issue' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['notes' => 'Geändert']),
        'softdeleted' => DB::table('kk_issued_items')->where('id', $this->deletedIssue)->update(['notes' => 'Geändert']),
        'message' => DB::table('kk_equipment_messages')->where('id', $this->message)->update(['body' => 'Geändert']),
        'new_message' => DB::table('kk_equipment_messages')->insert(['issued_item_id' => $this->issue, 'user_id' => $this->recipient->id, 'item_label' => 'Neu', 'body' => 'Neu']),
        'new_history' => DB::table('kk_issued_items')->insert([
            'user_id' => $this->recipient->id, 'item_type_id' => $this->type, 'size_id' => $this->size,
            'group_id' => $this->group, 'issued_at' => now(), 'returned_at' => now(),
        ]),
        'delete_history' => DB::table('kk_issued_items')->where('id', $this->issue)->delete(),
        'delete_message' => DB::table('kk_equipment_messages')->where('id', $this->message)->delete(),
        'operator' => DB::table('users')->where('id', $this->admin->id)->update(['ext_location_id' => $this->target->id]),
        'membership' => DB::table('x_location_user')->insert(['user_id' => $this->admin->id, 'location_id' => $this->target->id, 'role' => 'member']),
        'location' => DB::table('x_locations')->where('id', $this->target->id)->update(['name' => 'Geändert']),
        'reason' => $this->reason .= '!',
        'actor' => $this->admin = User::factory()->create(['is_admin' => true]),
        'optional' => Schema::drop('kk_equipment_messages'),
        'plan' => file_put_contents($this->path, json_encode([[...$this->plan[0], 'location_id' => $this->other->id]])),
    };
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'veraltet')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull();
})->with(['quantity', 'stock', 'type', 'size', 'group', 'issue', 'softdeleted', 'message', 'new_message', 'new_history',
    'delete_history', 'delete_message', 'operator', 'membership', 'location', 'reason', 'actor', 'optional', 'plan']);

it('blocks every matching current bulk issue including zero stock with technical IDs only', function (string $nullable) {
    $keys = match ($nullable) {
        'size' => ['size_id' => null], 'group' => ['group_id' => null],
        'both' => ['size_id' => null, 'group_id' => null], default => [],
    };
    DB::table('kk_stock')->where('id', $this->stock)->update([...$keys, 'quantity' => 0]);
    DB::table('kk_issued_items')->where('id', $this->issue)->update([...$keys, 'returned_at' => null]);
    expect(Artisan::call('locations:assign-clothing-stock', ['plan' => $this->path]))->toBe(1)
        ->and(Artisan::output())->toContain("kk_stock:{$this->stock}", "kk_issued_items:{$this->issue}", 'Keine automatische Rückgabe')
        ->not->toContain('Private', 'Bernd', 'Hanna', 'Niemals', 'SQLSTATE')
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('quantity'))->toBe(0)
        ->and(DB::table('kk_issued_items')->where('id', $this->issue)->value('returned_at'))->toBeNull();
})->with(['neither', 'size', 'group', 'both']);

it('allows zero stock and unrelated current issues without deriving historical ownership', function (string $case) {
    $changes = match ($case) {
        'different_size' => ['size_id' => null],
        'different_group' => ['group_id' => null],
        'different_type' => ['item_type_id' => DB::table('kk_item_types')->insertGetId(['name' => 'Anderer Typ'])],
        'tracked' => ['item_id' => DB::table('kk_items')->insertGetId(['item_type_id' => $this->type, 'code' => 'OTHER'])],
        default => ['deleted_at' => now()],
    };
    DB::table('kk_stock')->where('id', $this->stock)->update(['quantity' => 0]);
    DB::table('kk_issued_items')->where('id', $this->issue)->update([...$changes, 'returned_at' => null]);
    DB::table('users')->where('id', $this->recipient->id)->update(['ext_location_id' => $this->other->id, 'is_active' => false]);
    ($this->apply)();
    expect(DB::table('kk_stock')->find($this->stock))->toMatchObject(['quantity' => 0, 'ext_location_id' => $this->target->id]);
})->with(['different_size', 'different_group', 'different_type', 'tracked', 'softdeleted']);

it('still blocks assignment for new source-bound bulk records including corrected catalogs', function (string $changed) {
    $issued = app(IssuingService::class)->issueStock(
        $this->recipient, ItemType::findOrFail($this->type), Size::findOrFail($this->size),
        2, group: ItemGroup::findOrFail($this->group),
    );
    expect($issued->getAttributes()['ext_source_stock_id'])->toBe($this->stock);
    if ($changed === 'snapshot') {
        $issued->update(['item_type_id' => null, 'size_id' => null, 'group_id' => null]);
    } elseif ($changed === 'stock') {
        DB::table('kk_stock')->where('id', $this->stock)->update(['size_id' => null, 'group_id' => null]);
    }
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, "Offene Mengenausgabe kk_issued_items:{$issued->id}")
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull();
})->with(['unchanged', 'snapshot', 'stock']);

it('keeps the existing stock assignment workflow available on older unsourced schema', function () {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_26_000000_record_clothing_issuance_stock_source.php');
    $migration->down();
    ($this->apply)();
    expect(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBe($this->target->id)
        ->and(Schema::hasColumn('kk_issued_items', 'ext_source_stock_id'))->toBeFalse();
});

it('hashes returned and softdeleted null-key history and tolerates absent optional messages', function () {
    DB::table('kk_stock')->where('id', $this->stock)->update(['size_id' => null, 'group_id' => null]);
    DB::table('kk_issued_items')->whereIn('id', [$this->issue, $this->deletedIssue])->update(['size_id' => null, 'group_id' => null]);
    Schema::drop('kk_equipment_messages');
    $hash = ($this->preview)()['hash'];
    DB::table('kk_issued_items')->where('id', $this->deletedIssue)->update(['notes' => 'Geändert']);
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'veraltet');
    ($this->apply)();
    expect(Schema::hasTable('kk_equipment_messages'))->toBeFalse();
});

it('rejects orphan catalog references even if an installation removed its catalog FK', function (string $column) {
    foreach (Schema::getForeignKeys('kk_stock') as $foreign) {
        if (in_array($column, $foreign['columns'], true)) {
            Schema::table('kk_stock', fn (Blueprint $table) => $table->dropForeign($foreign['name']));
        }
    }
    DB::table('kk_stock')->where('id', $this->stock)->update([$column => 999999]);
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, 'Katalogreferenz')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull();
})->with(['item_type_id', 'size_id', 'group_id']);

it('refuses invalid stock targets and batches atomically', function (string $case) {
    $second = DB::table('kk_stock')->insertGetId(['item_type_id' => $this->type, 'quantity' => 2]);
    file_put_contents($this->path, json_encode([...$this->plan, [...$this->plan[0], 'id' => $second]]));
    match ($case) {
        'assigned' => DB::table('kk_stock')->where('id', $second)->update(['ext_location_id' => $this->other->id]),
        'missing' => DB::table('kk_stock')->where('id', $second)->delete(),
        'negative' => DB::table('kk_stock')->where('id', $second)->update(['quantity' => -1]),
        'inactive' => DB::table('x_locations')->where('id', $this->target->id)->update(['is_active' => false]),
        'target_missing' => DB::table('x_locations')->where('id', $this->target->id)->delete(),
        'open_second' => DB::table('kk_issued_items')->insert(['item_type_id' => $this->type, 'user_id' => $this->recipient->id, 'issued_at' => now()]),
    };
    expect(fn () => ($this->apply)())->toThrow(RuntimeException::class)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['assigned', 'missing', 'negative', 'inactive', 'target_missing', 'open_second']);

it('fails closed unless an actual validated restrictive FK exists without unsafe counterparts', function (string $case) {
    $hash = ($this->preview)()['hash'];
    if (! str_starts_with($case, 'counterpart_')) {
        DB::statement('ALTER TABLE kk_stock DROP CONSTRAINT kk_stock_ext_location_id_foreign');
    }
    $definition = match (str_replace('counterpart_', '', $case)) {
        'set_null' => 'REFERENCES x_locations(id) ON DELETE SET NULL',
        'cascade_delete' => 'REFERENCES x_locations(id) ON DELETE CASCADE',
        'cascade_update' => 'REFERENCES x_locations(id) ON UPDATE CASCADE ON DELETE RESTRICT',
        'not_valid' => 'REFERENCES x_locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID',
        'wrong_target' => 'REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT',
        'deferrable' => 'REFERENCES x_locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT DEFERRABLE',
        default => null,
    };
    if ($definition !== null) {
        DB::statement('ALTER TABLE kk_stock ADD CONSTRAINT custom_stock_owner FOREIGN KEY (ext_location_id) '.$definition);
    }
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'validierten restriktiven')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
    $this->migration->up();
    ($this->apply)($hash);
})->with(['absent', 'set_null', 'cascade_delete', 'cascade_update', 'not_valid', 'wrong_target', 'deferrable',
    'counterpart_set_null', 'counterpart_cascade_delete', 'counterpart_cascade_update', 'counterpart_not_valid', 'counterpart_wrong_target']);

it('accepts restrictive custom names and rejects missing or narrowed journal expansion', function (string $case) {
    DB::statement('ALTER TABLE kk_stock RENAME CONSTRAINT kk_stock_ext_location_id_foreign TO custom_stock_owner');
    $hash = ($this->preview)()['hash'];
    if ($case === 'narrowed') {
        $this->journalMigration->down();
    } elseif ($case === 'absent') {
        DB::statement('ALTER TABLE x_location_data_assignments DROP CONSTRAINT x_location_data_assignments_target');
    }
    if ($case !== 'custom') {
        expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'Stock-Erweiterung')
            ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull();
    } else {
        ($this->apply)($hash);
        expect(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBe($this->target->id);
    }
})->with(['custom', 'narrowed', 'absent']);

it('rolls the complete batch back on a journal conflict and emits safe CLI errors', function () {
    DB::table('x_location_data_assignments')->insert([
        'target_table' => 'kk_stock', 'target_id' => $this->stock, 'location_id' => $this->target->id,
        'assigned_by' => $this->admin->id, 'reason' => 'Prior review', 'assigned_at' => now(),
        'batch_id' => Str::uuid(), 'state_hash' => str_repeat('a', 64),
    ]);
    expect(Artisan::call('locations:assign-clothing-stock', [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id, '--reason' => $this->reason,
        '--expect' => ($this->preview)()['hash'], '--backup-confirmed' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Datenbankkonflikt')->not->toContain('SQLSTATE', 'insert', $this->reason)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull();
});

it('retains stock history across rejected journal and column rollback and prevents erasure', function (string $operation) {
    ($this->apply)();
    $before = DB::table('x_location_data_assignments')->get();
    expect(fn () => DB::transaction(fn () => match ($operation) {
        'journal' => $this->journalMigration->down(),
        'column' => $this->migration->down(),
        'update' => DB::table('x_location_data_assignments')->update(['reason' => 'rewrite']),
        'delete' => DB::table('x_location_data_assignments')->delete(),
        'truncate' => DB::statement('TRUNCATE x_location_data_assignments'),
        'location_delete' => DB::table('x_locations')->where('id', $this->target->id)->delete(),
        'location_update' => DB::table('x_locations')->where('id', $this->target->id)->update(['id' => 999999]),
    }))->toThrow($operation === 'journal' || $operation === 'column' ? RuntimeException::class : QueryException::class)
        ->and(DB::table('x_location_data_assignments')->get())->toEqual($before)
        ->and(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBe($this->target->id);
})->with(['journal', 'column', 'update', 'delete', 'truncate', 'location_delete', 'location_update']);

it('refuses column removal for owned rows even without journal history', function () {
    DB::table('kk_stock')->where('id', $this->stock)->update(['ext_location_id' => $this->target->id]);
    expect(fn () => DB::transaction(fn () => $this->migration->down()))->toThrow(RuntimeException::class, 'Owned stock');
});

it('refuses column removal on journal history even after the stock row is gone', function () {
    ($this->apply)();
    DB::table('kk_stock')->where('id', $this->stock)->delete();
    expect(fn () => DB::transaction(fn () => $this->migration->down()))->toThrow(RuntimeException::class, 'history')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(1);
});

it('upgrades without backfill and preserves existing attendance and tracked-item journals', function () {
    foreach (['x_att_lists', 'kk_items'] as $table) {
        DB::table('x_location_data_assignments')->insert([
            'target_table' => $table, 'target_id' => 999999, 'location_id' => $this->target->id,
            'assigned_by' => $this->admin->id, 'reason' => 'Prior review', 'assigned_at' => now(),
            'batch_id' => Str::uuid(), 'state_hash' => str_repeat('a', 64),
        ]);
    }
    $before = DB::table('x_location_data_assignments')->get();
    $this->journalMigration->down();
    $this->migration->down();
    $this->migration->up();
    $this->migration->up();
    $this->journalMigration->up();
    expect(DB::table('kk_stock')->where('id', $this->stock)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->get())->toEqual($before);
    $column = collect(Schema::getColumns('kk_stock'))->firstWhere('name', 'ext_location_id');
    expect($column['nullable'])->toBeTrue()->and($column['default'])->toBeNull();
    ($this->apply)();
});

it('permits the same catalog tuple at distinct Standort owners without merging', function (string $nullable) {
    $keys = ['item_type_id' => $this->type, 'size_id' => $nullable === 'both' ? null : $this->size, 'group_id' => $nullable === 'both' ? null : $this->group];
    DB::table('kk_stock')->where('id', $this->stock)->update($keys);
    ($this->apply)();
    DB::table('kk_stock')->insert([
        ...$keys, 'quantity' => 2, 'ext_location_id' => $this->other->id,
    ]);
    expect(DB::table('kk_stock')->count())->toBe(2);
})->with(['neither', 'both']);

it('skips missing optional stock or Locations tables without replacements', function (string $case) {
    if ($case === 'stock') {
        Schema::rename('kk_stock', 'saved_kk_stock');
    } else {
        Schema::rename('x_locations', 'saved_x_locations');
    }
    $this->migration->up();
    expect(Schema::hasTable($case === 'stock' ? 'kk_stock' : 'x_locations'))->toBeFalse();
})->with(['stock', 'locations']);

it('fails safely with missing ownership schema and skips an absent journal upgrade', function (string $case) {
    if ($case === 'journal') {
        Schema::rename('x_location_data_assignments', 'saved_x_location_data_assignments');
        $this->journalMigration->up();
        $this->journalMigration->down();
        expect(Schema::hasTable('x_location_data_assignments'))->toBeFalse();
    } else {
        $this->migration->down();
        expect(Schema::hasColumn('kk_stock', 'ext_location_id'))->toBeFalse();
    }
    expect(Artisan::call('locations:assign-clothing-stock', ['plan' => $this->path]))->toBe(1)
        ->and(Artisan::output())->toContain('installiert')->not->toContain('SQLSTATE', 'select', 'Private');
})->with(['journal', 'column']);

it('bounds combined current historical and message fanout', function (string $table) {
    $row = $table === 'kk_issued_items'
        ? ['item_type_id' => $this->type, 'size_id' => $this->size, 'group_id' => $this->group, 'user_id' => $this->recipient->id, 'issued_at' => now(), 'returned_at' => now()]
        : ['issued_item_id' => $this->deletedIssue, 'user_id' => $this->recipient->id, 'item_label' => 'Private Meldung', 'body' => 'Privater Inhalt'];
    for ($i = 0; $i < 9998; $i += 500) {
        DB::table($table)->insert(array_fill(0, min(500, 9998 - $i), $row));
    }
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, 'mehr als 10000')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['kk_issued_items', 'kk_equipment_messages']);

it('rejects invalid CLI actor IDs and disables the command with Locations', function () {
    foreach (['0', '-1', '1.5', '01', '999999999999999999999'] as $by) {
        expect(Artisan::call('locations:assign-clothing-stock', ['plan' => $this->path, '--by' => $by]))->toBe(1)
            ->and(Artisan::output())->toContain('positive ganzzahlige ID');
    }
    config(['locations.enabled' => false]);
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, 'aktiviert');
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--raw'], base_path(), ['LOCATIONS_ENABLED' => 'false']);
    $process->mustRun();
    expect($process->getOutput())->not->toContain('locations:assign-clothing-stock');
});
