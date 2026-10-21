<?php

use App\Models\User;
use Extensions\Locations\Console\AssignClothingCommand;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\ClothingLocationAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
    $this->target = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'clothing-south']);
    $this->other = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'clothing-north']);
    $this->admin = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => true]);
    $this->recipient = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->historical = User::factory()->create(['name' => 'Lena Leiter']);
    DB::table('users')->where('id', $this->recipient->id)->update(['ext_location_id' => $this->target->id]);
    DB::table('users')->whereIn('id', [$this->admin->id, $this->historical->id])->update(['ext_location_id' => $this->other->id]);
    $this->type = DB::table('kk_item_types')->insertGetId(['name' => 'Vertrauliche Jacke', 'is_tracked' => true]);
    $this->item = DB::table('kk_items')->insertGetId([
        'item_type_id' => $this->type, 'code' => 'VERTRAULICH-001', 'notes' => 'Privater Bestand',
        'created_by' => $this->historical->id, 'updated_by' => $this->historical->id,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->issue = DB::table('kk_issued_items')->insertGetId([
        'item_id' => $this->item, 'user_id' => $this->recipient->id, 'issued_at' => now(),
        'created_by' => $this->historical->id, 'updated_by' => $this->historical->id,
        'notes' => 'Private Ausgabe', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->oldIssue = DB::table('kk_issued_items')->insertGetId([
        'item_id' => $this->item, 'user_id' => $this->historical->id, 'issued_at' => now()->subYear(),
        'returned_at' => now()->subMonth(), 'deleted_at' => now(), 'notes' => 'Private Historie',
    ]);
    $this->message = DB::table('kk_equipment_messages')->insertGetId([
        'user_id' => $this->historical->id, 'issued_item_id' => $this->oldIssue,
        'item_label' => 'Private Meldung', 'body' => 'Vertraulicher Inhalt',
        'handled_by' => $this->historical->id, 'handled_at' => now(),
    ]);
    $this->treatment = DB::table('kk_item_treatments')->insertGetId([
        'item_id' => $this->item, 'type' => 'washing', 'treated_at' => now()->toDateString(), 'notes' => 'Private Behandlung',
    ]);
    $this->service = app(ClothingLocationAssignment::class);
    $this->plan = [['table' => 'kk_items', 'id' => $this->item, 'location_id' => $this->target->id, 'expected_location_id' => null]];
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $this->path = storage_path('framework/testing/clothing-assignment-'.Str::uuid().'.json');
    file_put_contents($this->path, json_encode($this->plan, JSON_THROW_ON_ERROR));
    $this->reason = 'Bestand und gesamte Historie geprüft';
    $this->preview = fn () => $this->service->run($this->path, by: $this->admin->id, reason: $this->reason);
    $this->apply = fn (?string $hash = null) => $this->service->run(
        $this->path, true, $this->admin->id, $this->reason, $hash ?? ($this->preview)()['hash'], true,
    );
});

afterEach(function () {
    if (isset($this->path)) {
        File::delete($this->path);
    }
});

it('defaults to deterministic private dry runs with no implicit target', function () {
    $before = DB::table('kk_items')->find($this->item);
    expect(Artisan::call('locations:assign-clothing', ['plan' => $this->path]))->toBe(0);
    $output = Artisan::output();
    $result = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($result['dry_run'])->toBeTrue()
        ->and($result['batch_id'])->toBeNull()
        ->and($result['hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($result['rows'])->toBe([['table' => 'kk_items', 'id' => $this->item, 'location_id' => $this->target->id, 'status' => 'ready_history_requires_review']])
        ->and($output)->not->toContain('Privat', 'Vertraulich', 'Hanna', 'Bernd', 'Lena')
        ->and($this->service->run($this->path)['hash'])->toBe($result['hash'])
        ->and(DB::table('kk_items')->find($this->item))->toEqual($before)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
});

it('changes only explicit raw ownership and appends private immutable approval metadata', function () {
    $tables = ['kk_items', 'kk_issued_items', 'kk_equipment_messages', 'kk_item_treatments',
        'kk_stock', 'kk_item_types', 'users', 'x_location_user', 'module_user', 'x_location_module_roles', 'activity_logs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->get();
    }
    $preview = ($this->preview)();
    expect(Artisan::call(AssignClothingCommand::class, [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id,
        '--reason' => '  '.$this->reason.'  ', '--backup-confirmed' => true, '--expect' => $preview['hash'],
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    foreach ($tables as $table) {
        if ($table === 'kk_items') {
            $before[$table][0]->ext_location_id = $this->target->id;
        }
        expect(DB::table($table)->get())->toEqual($before[$table]);
    }
    $journal = DB::table('x_location_data_assignments')->sole();
    expect($result['rows'][0]['status'])->toBe('assigned')
        ->and($journal->target_table)->toBe('kk_items')
        ->and($journal->target_id)->toBe($this->item)
        ->and($journal->assigned_by)->toBe($this->admin->id)
        ->and($journal->reason)->toBe($this->reason)
        ->and($journal->state_hash)->toBe($preview['hash'])
        ->and($journal->batch_id)->toBe($result['batch_id'])
        ->and(Schema::getColumnListing('x_location_data_assignments'))->not->toContain('payload', 'notes', 'snapshot');
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('rejects malformed bounded or non-null plans', function (string $case) {
    $row = $this->plan[0];
    $data = match ($case) {
        'duplicate' => [$row, $row],
        'table' => [[...$row, 'table' => 'kk_stock']],
        'string_id' => [[...$row, 'id' => (string) $row['id']]],
        'float_id' => [[...$row, 'id' => 1.5]],
        'negative' => [[...$row, 'location_id' => -1]],
        'null_id' => [[...$row, 'id' => null]],
        'extra' => [[...$row, 'reason' => 'forbidden']],
        'expected' => [[...$row, 'expected_location_id' => $this->other->id]],
        'missing_expected' => [['table' => 'kk_items', 'id' => $this->item, 'location_id' => $this->target->id]],
        'empty' => [],
        'many' => array_fill(0, 501, $row),
        default => null,
    };
    file_put_contents($this->path, match ($case) {
        'invalid' => '[{',
        'oversize' => str_repeat(' ', 262145),
        'object' => '{}',
        default => json_encode($data),
    });
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['duplicate', 'table', 'string_id', 'float_id', 'negative', 'null_id', 'extra', 'expected', 'missing_expected', 'empty', 'many', 'invalid', 'oversize', 'object']);

it('requires every explicit apply safeguard', function (string $case) {
    $hash = ($this->preview)()['hash'];
    expect(fn () => $this->service->run(
        $this->path, true, $case === 'by' ? null : $this->admin->id,
        match ($case) {
            'reason' => ' ', 'long' => str_repeat('x', 501), default => $this->reason
        },
        match ($case) {
            'expect' => null, 'bad_hash' => 'invalid', default => $hash
        }, $case !== 'backup',
    ))->toThrow(RuntimeException::class)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull();
})->with(['by', 'reason', 'long', 'expect', 'bad_hash', 'backup']);

it('requires an active global administrator rather than location or module authority', function (string $case) {
    match ($case) {
        'member' => DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]),
        'inactive' => DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]),
        'left' => DB::table('users')->where('id', $this->admin->id)->update(['status' => 'left']),
        'location_admin' => DB::table('x_location_user')->insert(['user_id' => $this->admin->id, 'location_id' => $this->target->id, 'role' => 'admin']),
    };
    if ($case === 'location_admin') {
        DB::table('users')->where('id', $this->admin->id)->update(['is_admin' => false]);
    }
    expect(fn () => ($this->apply)())->toThrow(RuntimeException::class, 'globaler Administrator')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['member', 'inactive', 'left', 'location_admin']);

it('binds approval to exact actor reason and relevant state', function (string $change) {
    $hash = ($this->preview)()['hash'];
    match ($change) {
        'item' => DB::table('kk_items')->where('id', $this->item)->update(['notes' => 'Geändert']),
        'current' => DB::table('kk_issued_items')->where('id', $this->issue)->update(['notes' => 'Geändert']),
        'historical' => DB::table('kk_issued_items')->where('id', $this->oldIssue)->update(['notes' => 'Geändert']),
        'message' => DB::table('kk_equipment_messages')->where('id', $this->message)->update(['body' => 'Geändert']),
        'treatment' => DB::table('kk_item_treatments')->where('id', $this->treatment)->update(['notes' => 'Geändert']),
        'new_message' => DB::table('kk_equipment_messages')->insert(['user_id' => $this->recipient->id, 'issued_item_id' => $this->issue, 'item_label' => 'Neu', 'body' => 'Neu']),
        'new_treatment' => DB::table('kk_item_treatments')->insert(['item_id' => $this->item, 'type' => 'washing', 'treated_at' => now()->toDateString()]),
        'new_history' => DB::table('kk_issued_items')->insert(['item_id' => $this->item, 'user_id' => $this->historical->id, 'issued_at' => now(), 'returned_at' => now()]),
        'deleted_history' => DB::table('kk_issued_items')->where('id', $this->oldIssue)->delete(),
        'deleted_message' => DB::table('kk_equipment_messages')->where('id', $this->message)->delete(),
        'deleted_treatment' => DB::table('kk_item_treatments')->where('id', $this->treatment)->delete(),
        'recipient' => DB::table('users')->where('id', $this->recipient->id)->update(['status' => 'inactive']),
        'membership' => DB::table('x_location_user')->insert(['user_id' => $this->recipient->id, 'location_id' => $this->other->id, 'role' => 'member']),
        'operator_membership' => DB::table('x_location_user')->insert(['user_id' => $this->admin->id, 'location_id' => $this->target->id, 'role' => 'member']),
        'operator_state' => DB::table('users')->where('id', $this->admin->id)->update(['ext_location_id' => $this->target->id]),
        'location' => DB::table('x_locations')->where('id', $this->target->id)->update(['short_name' => 'Süd']),
        'reason' => $this->reason .= '!',
        'actor' => $this->admin = User::factory()->create(['is_admin' => true]),
    };
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'veraltet')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull();
})->with(['item', 'current', 'historical', 'message', 'treatment', 'new_message', 'new_treatment', 'new_history', 'deleted_history', 'deleted_message', 'deleted_treatment',
    'recipient', 'membership', 'operator_membership', 'operator_state', 'location', 'reason', 'actor']);

it('accepts actual primary or pivot membership but never an administrator bypass', function (string $case) {
    if ($case !== 'primary') {
        DB::table('users')->where('id', $this->recipient->id)->update(['ext_location_id' => $this->other->id, 'is_admin' => true]);
    }
    if ($case === 'pivot') {
        DB::table('x_location_user')->insert(['user_id' => $this->recipient->id, 'location_id' => $this->target->id, 'role' => 'member']);
    }
    if ($case === 'foreign_admin') {
        expect(fn () => ($this->apply)())->toThrow(RuntimeException::class, 'keine tatsächliche Mitgliedschaft');
    } else {
        ($this->apply)();
        expect(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBe($this->target->id);
    }
})->with(['primary', 'pivot', 'foreign_admin']);

it('ignores historical and actor memberships while retaining every historical child in approval', function (string $case) {
    DB::table('kk_issued_items')->where('id', $this->oldIssue)->update([
        'returned_at' => $case === 'softdeleted_only' ? null : now(),
        'deleted_at' => $case === 'returned_only' ? null : now(),
    ]);
    $hash = ($this->preview)()['hash'];
    // Historical recipients/creators/handlers are not current ownership candidates.
    DB::table('users')->where('id', $this->historical->id)->update(['ext_location_id' => null, 'is_active' => false]);
    DB::table('x_location_user')->insert(['user_id' => $this->historical->id, 'location_id' => $this->other->id, 'role' => 'member']);
    expect(($this->preview)()['hash'])->toBe($hash);
    ($this->apply)($hash);
})->with(['returned_only', 'softdeleted_only', 'both']);

it('refuses multiple open issues even for the same recipient', function () {
    DB::table('kk_issued_items')->where('id', $this->oldIssue)->update([
        'user_id' => $this->recipient->id, 'returned_at' => null, 'deleted_at' => null,
    ]);
    expect(fn () => ($this->apply)())->toThrow(RuntimeException::class, 'Mehrere offene Ausgaben');
});

it('refuses reassignment deleted items missing records and inactive or missing targets atomically', function (string $case) {
    $second = DB::table('kk_items')->insertGetId(['item_type_id' => $this->type, 'code' => 'SECOND']);
    file_put_contents($this->path, json_encode([...$this->plan, [...$this->plan[0], 'id' => $second]]));
    match ($case) {
        'assigned' => DB::table('kk_items')->where('id', $second)->update(['ext_location_id' => $this->other->id]),
        'deleted' => DB::table('kk_items')->where('id', $second)->update(['deleted_at' => now()]),
        'missing' => DB::table('kk_items')->where('id', $second)->delete(),
        'inactive' => DB::table('x_locations')->where('id', $this->target->id)->update(['is_active' => false]),
        'missing_target' => file_put_contents($this->path, json_encode([[...$this->plan[0], 'location_id' => 999999]])),
    };
    expect(fn () => ($this->apply)())->toThrow(RuntimeException::class)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['assigned', 'deleted', 'missing', 'inactive', 'missing_target']);

it('rejects lost membership after approval', function () {
    DB::table('users')->where('id', $this->recipient->id)->update(['ext_location_id' => null]);
    DB::table('x_location_user')->insert(['user_id' => $this->recipient->id, 'location_id' => $this->target->id, 'role' => 'member']);
    $hash = ($this->preview)()['hash'];
    DB::table('x_location_user')->where('user_id', $this->recipient->id)->delete();
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'keine tatsächliche Mitgliedschaft');
});

it('hashes optional child availability and accepts missing optional child tables', function () {
    $hash = ($this->preview)()['hash'];
    Schema::drop('kk_equipment_messages');
    Schema::drop('kk_item_treatments');
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'veraltet');
    ($this->apply)();
});

it('keeps the whole batch atomic on a late journal conflict and sanitizes SQL errors', function () {
    DB::table('x_location_data_assignments')->insert([
        'target_table' => 'kk_items', 'target_id' => $this->item, 'location_id' => $this->other->id,
        'assigned_by' => $this->admin->id, 'reason' => 'Private Historie', 'assigned_at' => now(),
        'batch_id' => Str::uuid(), 'state_hash' => str_repeat('a', 64),
    ]);
    $preview = ($this->preview)();
    expect(Artisan::call('locations:assign-clothing', [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id, '--reason' => $this->reason,
        '--expect' => $preview['hash'], '--backup-confirmed' => true,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('Datenbankkonflikt')->not->toContain('insert', $this->reason, 'Private')
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull();
});

it('keeps clothing history immutable against updates deletes and truncation', function (string $operation) {
    ($this->apply)();
    expect(fn () => DB::transaction(fn () => match ($operation) {
        'update' => DB::table('x_location_data_assignments')->update(['reason' => 'rewrite']),
        'delete' => DB::table('x_location_data_assignments')->delete(),
        'truncate' => DB::statement('TRUNCATE x_location_data_assignments'),
    }))->toThrow(QueryException::class, 'append-only')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(1);
})->with(['update', 'delete', 'truncate']);

it('refuses journal rollback without erasing clothing or attendance history', function () {
    ($this->apply)();
    DB::table('x_location_data_assignments')->insert([
        'target_table' => 'x_att_lists', 'target_id' => 999999, 'location_id' => $this->target->id,
        'assigned_by' => $this->admin->id, 'reason' => 'Attendance history', 'assigned_at' => now(),
        'batch_id' => Str::uuid(), 'state_hash' => str_repeat('a', 64),
    ]);
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_24_000000_allow_clothing_location_assignments.php');
    expect(fn () => DB::transaction(fn () => $migration->down()))->toThrow(RuntimeException::class, 'refusing')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(2);
});

it('can narrow and reexpand the journal while retaining attendance history', function () {
    DB::table('x_location_data_assignments')->insert([
        'target_table' => 'x_att_templates', 'target_id' => 999999, 'location_id' => $this->target->id,
        'assigned_by' => $this->admin->id, 'reason' => 'Attendance history', 'assigned_at' => now(),
        'batch_id' => Str::uuid(), 'state_hash' => str_repeat('a', 64),
    ]);
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_24_000000_allow_clothing_location_assignments.php');
    $migration->down();
    expect(DB::table('x_location_data_assignments')->count())->toBe(1);
    $migration->up();
    ($this->apply)();
    expect(DB::table('x_location_data_assignments')->count())->toBe(2);
});

it('upgrades absent and custom clothing FKs to validated restrictive ownership', function (string $case) {
    Schema::table('kk_items', fn (Blueprint $table) => $table->dropForeign('kk_items_ext_location_id_foreign'));
    if ($case === 'custom') {
        Schema::table('kk_items', fn (Blueprint $table) => $table->foreign('ext_location_id', 'custom_clothing_owner')->references('id')->on('x_locations')->nullOnDelete());
    }
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_24_000001_restrict_clothing_location_deletion.php');
    $migration->up();
    $migration->up();
    $migration->down();
    $foreign = collect(Schema::getForeignKeys('kk_items'))->firstWhere('columns', ['ext_location_id']);
    expect($foreign['on_delete'])->toBe('restrict')
        ->and($foreign['on_update'])->toBe('restrict');
    DB::table('kk_items')->where('id', $this->item)->update(['ext_location_id' => $this->target->id]);
    expect(fn () => DB::transaction(fn () => DB::table('x_locations')->where('id', $this->target->id)->delete()))->toThrow(QueryException::class)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBe($this->target->id);
    expect(fn () => DB::transaction(fn () => DB::table('kk_items')->where('id', $this->item)->update(['ext_location_id' => 999999])))->toThrow(QueryException::class);
})->with(['absent', 'custom']);

it('fails closed on apply when the actual restrictive validated FK is missing', function (string $case) {
    $hash = ($this->preview)()['hash'];
    Schema::table('kk_items', fn (Blueprint $table) => $table->dropForeign('kk_items_ext_location_id_foreign'));
    if ($case === 'set_null') {
        DB::statement('ALTER TABLE kk_items ADD CONSTRAINT custom_owner FOREIGN KEY (ext_location_id) REFERENCES x_locations(id) ON DELETE SET NULL');
    } elseif ($case === 'not_valid') {
        DB::statement('ALTER TABLE kk_items ADD CONSTRAINT custom_owner FOREIGN KEY (ext_location_id) REFERENCES x_locations(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID');
    } elseif ($case === 'wrong_target') {
        DB::statement('ALTER TABLE kk_items ADD CONSTRAINT custom_owner FOREIGN KEY (ext_location_id) REFERENCES users(id) ON UPDATE RESTRICT ON DELETE RESTRICT');
    } elseif ($case === 'cascade_update') {
        DB::statement('ALTER TABLE kk_items ADD CONSTRAINT custom_owner FOREIGN KEY (ext_location_id) REFERENCES x_locations(id) ON UPDATE CASCADE ON DELETE RESTRICT');
    }
    expect(fn () => ($this->apply)($hash))->toThrow(RuntimeException::class, 'validierten restriktiven')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull();
})->with(['absent', 'set_null', 'not_valid', 'wrong_target', 'cascade_update']);

it('accepts a validated custom restrictive FK without relying on a conventional name', function () {
    $hash = ($this->preview)()['hash'];
    DB::statement('ALTER TABLE kk_items RENAME CONSTRAINT kk_items_ext_location_id_foreign TO custom_clothing_owner');
    ($this->apply)($hash);
    expect(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBe($this->target->id);
});

it('bounds the total historical and current child fanout before reading payloads', function (string $table) {
    $row = match ($table) {
        'kk_issued_items' => ['item_id' => $this->item, 'user_id' => $this->historical->id, 'issued_at' => now(), 'returned_at' => now()],
        'kk_equipment_messages' => ['issued_item_id' => $this->oldIssue, 'user_id' => $this->historical->id, 'item_label' => 'Historie', 'body' => 'Privater Inhalt'],
        'kk_item_treatments' => ['item_id' => $this->item, 'type' => 'washing', 'treated_at' => now()->toDateString()],
    };
    for ($i = 0; $i < 9997; $i += 500) {
        DB::table($table)->insert(array_fill(0, min(500, 9997 - $i), $row));
    }
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, 'mehr als 10000')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('kk_items')->where('id', $this->item)->value('ext_location_id'))->toBeNull();
})->with(['kk_issued_items', 'kk_equipment_messages', 'kk_item_treatments']);

it('skips optional missing item tables or columns without creating replacements', function (string $case) {
    $migration = require base_path('extensions/Locations/database/migrations/2026_12_24_000001_restrict_clothing_location_deletion.php');
    if ($case === 'table') {
        Schema::rename('kk_items', 'saved_kk_items');
    } else {
        Schema::table('kk_items', function (Blueprint $table) {
            $table->dropForeign('kk_items_ext_location_id_foreign');
            $table->dropColumn('ext_location_id');
        });
    }
    $migration->up();
    expect(Schema::hasColumn('kk_items', 'ext_location_id'))->toBeFalse();
})->with(['table', 'column']);

it('does not register the clothing command when Locations is disabled', function () {
    $this->app['config']->set('locations.enabled', false);
    expect(fn () => ($this->preview)())->toThrow(RuntimeException::class, 'aktiviert');
    // Registration itself is tested in a separately booted application.
    $process = new Process([PHP_BINARY, 'artisan', 'list', '--raw'], base_path(), ['LOCATIONS_ENABLED' => 'false']);
    $process->mustRun();
    expect($process->getOutput())->not->toContain('locations:assign-clothing');
});

it('rejects invalid CLI actor IDs without exposing payload', function (string $by) {
    expect(Artisan::call('locations:assign-clothing', ['plan' => $this->path, '--by' => $by]))->toBe(1)
        ->and(Artisan::output())->toContain('positive ganzzahlige ID')->not->toContain('Private');
})->with(['0', '-1', '1.5', '01', '999999999999999999999']);
