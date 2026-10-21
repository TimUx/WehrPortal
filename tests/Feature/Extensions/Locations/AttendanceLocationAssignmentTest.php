<?php

use App\Models\Module;
use App\Models\User;
use App\Services\MemberRemoval;
use Extensions\Anwesenheit\Filament\Resources\TemplateResource;
use Extensions\Anwesenheit\Models\AttendanceList;
use Extensions\Anwesenheit\Services\AttendanceService;
use Extensions\Locations\Console\AssignAttendanceCommand;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\AttendanceLocationAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Module::where('key', 'anwesenheit')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Mail::fake();
    Notification::fake();
    $this->target = Location::create(['name' => 'FF Musterstadt Süd', 'slug' => 'assignment-south']);
    $this->other = Location::create(['name' => 'FF Musterstadt Nord', 'slug' => 'assignment-north']);
    $this->admin = User::factory()->create(['name' => 'Hanna Hydrant', 'is_admin' => true]);
    $this->creator = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader = User::factory()->create(['name' => 'Lena Leiter']);
    DB::table('users')->where('id', $this->creator->id)->update(['ext_location_id' => $this->target->id]);
    DB::table('users')->where('id', $this->leader->id)->update(['ext_location_id' => $this->other->id]);
    DB::table('x_location_user')->insert(['user_id' => $this->leader->id, 'location_id' => $this->target->id, 'role' => 'member']);
    $this->template = DB::table('x_att_templates')->insertGetId([
        'name' => 'Vertrauliche Vorlage', 'leader_id' => $this->leader->id,
        'recipients' => 'vorlage@example.com', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->list = DB::table('x_att_lists')->insertGetId([
        'title' => 'Vertraulicher Übungsabend', 'notes' => 'Vertrauliche Notizen', 'recipients' => 'liste@example.com',
        'created_by' => $this->creator->id, 'leader_id' => $this->leader->id, 'template_id' => $this->template,
        'source_event_id' => 999999, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('x_att_participants')->insert(['list_id' => $this->list, 'user_id' => $this->creator->id]);
    $this->file = DB::table('x_att_files')->insertGetId([
        'list_id' => $this->list, 'filename' => 'uebung.txt', 'data' => base64_encode('Vertrauliche Datei'),
        'size' => 18, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->assignment = app(AttendanceLocationAssignment::class);
    $this->plan = [
        ['table' => 'x_att_lists', 'id' => $this->list, 'location_id' => $this->target->id, 'expected_location_id' => null],
        ['table' => 'x_att_templates', 'id' => $this->template, 'location_id' => $this->target->id, 'expected_location_id' => null],
    ];
    File::ensureDirectoryExists(storage_path('framework/testing'));
    $this->path = storage_path('framework/testing/location-assignment-'.Str::uuid().'.json');
    file_put_contents($this->path, json_encode($this->plan, JSON_THROW_ON_ERROR));
    $this->previewPlan = fn (string $reason = 'Bestand und historische Verweise geprüft') => $this->assignment->run(
        $this->path, by: $this->admin->id, reason: $reason,
    );
    $this->applyPlan = fn (?string $hash = null) => $this->assignment->run(
        $this->path, true, $this->admin->id, 'Bestand und historische Verweise geprüft',
        $hash ?? ($this->previewPlan)()['hash'], true,
    );
});

afterEach(function () {
    if (isset($this->path)) {
        File::delete($this->path);
    }
});

it('defaults to a content-free deterministic dry run without modifying any row', function () {
    $before = DB::table('x_att_lists')->find($this->list);
    expect(Artisan::call(AssignAttendanceCommand::class, ['plan' => $this->path]))->toBe(0);
    $output = Artisan::output();
    $preview = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($preview['dry_run'])->toBeTrue()
        ->and($preview['hash'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($preview['batch_id'])->toBeNull()
        ->and($preview['rows'][0])->toBe(['table' => 'x_att_lists', 'id' => $this->list, 'location_id' => $this->target->id, 'status' => 'ready'])
        ->and($output)->not->toContain('Vertraulich', 'Hanna', 'Bernd', '@example.com', 'uebung.txt')
        ->and(DB::table('x_att_lists')->find($this->list))->toEqual($before)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
    file_put_contents($this->path, json_encode(array_reverse($this->plan)));
    expect($this->assignment->run($this->path)['hash'])->toBe($preview['hash']);
});

it('applies only explicit owners and appends a batch without changing payload children or sending mail', function () {
    $listBefore = (array) DB::table('x_att_lists')->find($this->list);
    $templateBefore = (array) DB::table('x_att_templates')->find($this->template);
    $filesBefore = DB::table('x_att_files')->get();
    $participantsBefore = DB::table('x_att_participants')->get();
    $logs = DB::table('activity_logs')->count();
    $owned = DB::table('x_att_lists')->insertGetId(['title' => 'Bereits zugeordnet', 'ext_location_id' => $this->other->id]);
    expect(Artisan::call(AssignAttendanceCommand::class, [
        'plan' => $this->path, '--by' => (string) $this->admin->id, '--reason' => 'Geprüfte Zuordnung',
    ]))->toBe(0);
    $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect(Artisan::call('locations:assign-attendance', [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id,
        '--reason' => 'Geprüfte Zuordnung', '--backup-confirmed' => true, '--expect' => $preview['hash'],
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['dry_run'])->toBeFalse()
        ->and($result['rows'][0]['status'])->toBe('assigned')
        ->and((array) DB::table('x_att_lists')->find($this->list))->toBe([...$listBefore, 'ext_location_id' => $this->target->id])
        ->and((array) DB::table('x_att_templates')->find($this->template))->toBe([...$templateBefore, 'ext_location_id' => $this->target->id])
        ->and(DB::table('x_att_files')->get())->toEqual($filesBefore)
        ->and(DB::table('x_att_participants')->get())->toEqual($participantsBefore)
        ->and(DB::table('x_att_lists')->where('id', $owned)->value('ext_location_id'))->toBe($this->other->id)
        ->and(DB::table('activity_logs')->count())->toBe($logs);
    $journal = DB::table('x_location_data_assignments')->orderBy('id')->get();
    expect($journal)->toHaveCount(2)
        ->and($journal[0]->batch_id)->toBe($journal[1]->batch_id)
        ->and($journal[0]->batch_id)->toBe($result['batch_id'])
        ->and($journal[0]->state_hash)->toBe($preview['hash'])
        ->and($journal[0]->assigned_by)->toBe($this->admin->id)
        ->and($journal[0]->reason)->toBe('Geprüfte Zuordnung');
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('validates the entire plan atomically and refuses existing ownership', function () {
    DB::table('x_att_templates')->where('id', $this->template)->update(['ext_location_id' => $this->other->id]);
    expect(fn () => ($this->applyPlan)())->toThrow(RuntimeException::class, 'bereits zugeordnet')
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
    expect(Artisan::call(AssignAttendanceCommand::class, ['plan' => $this->path]))->toBe(1);
});

it('rejects stale previews even when the changed state still passes membership checks', function (string $change) {
    $hash = ($this->previewPlan)()['hash'];
    match ($change) {
        'membership' => DB::table('x_location_user')->insert(['user_id' => $this->creator->id, 'location_id' => $this->other->id, 'role' => 'member']),
        'participant' => DB::table('x_att_participants')->insert(['list_id' => $this->list, 'user_id' => $this->leader->id]),
        'payload' => DB::table('x_att_lists')->where('id', $this->list)->update(['notes' => 'Nachträglich geändert']),
        'template' => DB::table('x_att_templates')->where('id', $this->template)->update(['recipients' => 'neu@example.com']),
        'file' => DB::table('x_att_files')->where('id', $this->file)->update(['data' => base64_encode('Geändert')]),
        'location' => DB::table('x_locations')->where('id', $this->target->id)->update(['short_name' => 'Süd']),
    };
    expect(fn () => ($this->applyPlan)($hash))->toThrow(RuntimeException::class, 'veraltet')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull();
})->with(['membership', 'participant', 'payload', 'template', 'file', 'location']);

it('checks every person link using actual primary or pivot membership without a global admin bypass', function (string $link) {
    $foreign = User::factory()->create(['name' => 'Frieda Fremd', 'is_admin' => true]);
    DB::table('users')->where('id', $foreign->id)->update(['ext_location_id' => null]);
    match ($link) {
        'creator' => DB::table('x_att_lists')->where('id', $this->list)->update(['created_by' => $foreign->id]),
        'leader' => DB::table('x_att_lists')->where('id', $this->list)->update(['leader_id' => $foreign->id]),
        'template_leader' => DB::table('x_att_templates')->where('id', $this->template)->update(['leader_id' => $foreign->id]),
        'participant' => DB::table('x_att_participants')->insert(['list_id' => $this->list, 'user_id' => $foreign->id]),
    };
    expect(fn () => ($this->applyPlan)())->toThrow(RuntimeException::class, 'keine tatsächliche Mitgliedschaft')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0);
})->with(['creator', 'leader', 'template_leader', 'participant']);

it('rechecks membership removal and active locations without using default locations or provenance', function (string $change) {
    $hash = ($this->previewPlan)()['hash'];
    match ($change) {
        'membership' => DB::table('x_location_user')->where('user_id', $this->leader->id)->where('location_id', $this->target->id)->delete(),
        'inactive' => DB::table('x_locations')->where('id', $this->target->id)->update(['is_active' => false]),
        'missing' => file_put_contents($this->path, json_encode([[...$this->plan[0], 'location_id' => 999999]])),
        'missing_record' => file_put_contents($this->path, json_encode([[...$this->plan[0], 'id' => 999999]])),
    };
    expect(fn () => ($this->applyPlan)($hash))->toThrow(RuntimeException::class)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull();
})->with(['membership', 'inactive', 'missing', 'missing_record']);

it('permits explicitly reviewed historical null references without guessing an owner from templates or events', function () {
    DB::table('x_att_lists')->where('id', $this->list)->update(['created_by' => null, 'leader_id' => null]);
    DB::table('x_att_templates')->where('id', $this->template)->update(['ext_location_id' => $this->other->id]);
    file_put_contents($this->path, json_encode([$this->plan[0]]));
    $preview = ($this->previewPlan)();
    expect($preview['rows'][0]['status'])->toBe('ready_null_person_refs_require_review');
    ($this->applyPlan)($preview['hash']);
    expect(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBe($this->target->id)
        ->and(DB::table('x_att_templates')->where('id', $this->template)->value('ext_location_id'))->toBe($this->other->id);
});

it('rejects malformed duplicate unsupported or non-null ownership mappings', function (string $case) {
    $row = $this->plan[0];
    $data = match ($case) {
        'duplicate' => [$row, $row],
        'unsupported' => [[...$row, 'table' => 'users']],
        'string_id' => [[...$row, 'id' => (string) $row['id']]],
        'negative' => [[...$row, 'location_id' => -1]],
        'extra' => [[...$row, 'notes' => 'nicht erlaubt']],
        'expected_non_null' => [[...$row, 'expected_location_id' => $this->other->id]],
        'expected_missing' => [['table' => 'x_att_lists', 'id' => $this->list, 'location_id' => $this->target->id]],
        'empty' => [],
        'too_many' => array_fill(0, 501, $row),
        default => null,
    };
    file_put_contents($this->path, match ($case) {
        'invalid_json' => '[{',
        'object' => '{}',
        'numeric_key_object' => json_encode((object) [$row]),
        'oversized' => str_repeat(' ', 262145),
        default => json_encode($data),
    });
    expect(Artisan::call(AssignAttendanceCommand::class, ['plan' => $this->path]))->toBe(1)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull();
})->with(['duplicate', 'unsupported', 'string_id', 'negative', 'extra', 'expected_non_null', 'expected_missing', 'empty', 'too_many', 'invalid_json', 'object', 'numeric_key_object', 'oversized']);

it('requires all apply safeguards and a currently active global administrator', function (string $case) {
    $options = ['plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id,
        '--reason' => 'Geprüft', '--backup-confirmed' => true, '--expect' => ($this->previewPlan)('Geprüft')['hash']];
    if (in_array($case, ['by', 'reason', 'backup-confirmed', 'expect'], true)) {
        unset($options['--'.$case]);
    } elseif ($case === 'blank_reason') {
        $options['--reason'] = ' ';
    } elseif ($case === 'not_admin') {
        $options['--by'] = (string) $this->leader->id;
        DB::table('x_location_user')->where('user_id', $this->leader->id)->update(['role' => 'admin']);
    } elseif ($case === 'inactive') {
        DB::table('users')->where('id', $this->admin->id)->update(['is_active' => false]);
    } elseif ($case === 'left') {
        DB::table('users')->where('id', $this->admin->id)->update(['status' => 'left']);
    } elseif ($case === 'deleted') {
        DB::table('users')->where('id', $this->admin->id)->delete();
    } elseif ($case === 'malformed_by') {
        $options['--by'] = '1.5';
    }
    expect(Artisan::call(AssignAttendanceCommand::class, $options))->toBe(1)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull();
})->with(['by', 'reason', 'backup-confirmed', 'expect', 'blank_reason', 'not_admin', 'inactive', 'left', 'deleted', 'malformed_by']);

it('makes assignment history immutable in the database', function (string $mutation) {
    ($this->applyPlan)();
    expect(fn () => DB::transaction(function () use ($mutation) {
        match ($mutation) {
            'update' => DB::table('x_location_data_assignments')->update(['reason' => 'ersetzt']),
            'delete' => DB::table('x_location_data_assignments')->delete(),
            'truncate' => DB::statement('TRUNCATE x_location_data_assignments'),
            'invalid_target' => DB::table('x_location_data_assignments')->insert([
                'target_table' => 'users', 'target_id' => 1, 'location_id' => $this->target->id,
                'assigned_by' => $this->admin->id, 'reason' => 'Geprüft', 'assigned_at' => now(),
                'batch_id' => (string) Str::uuid(), 'state_hash' => str_repeat('a', 64),
            ]),
        };
    }))->toThrow(QueryException::class)
        ->and(DB::table('x_location_data_assignments')->count())->toBe(2);
})->with(['update', 'delete', 'truncate', 'invalid_target']);

it('never transfers or deletes the historical assignment actor during member removal', function () {
    ($this->applyPlan)();
    $removal = app(MemberRemoval::class);
    expect($removal->references()->pluck('table')->all())->not->toContain('x_location_data_assignments')
        ->and(fn () => $this->admin->delete())->toThrow(ValidationException::class)
        ->and(fn () => $removal->transferAndDelete($this->admin, $this->leader, $this->creator))->toThrow(ValidationException::class)
        ->and(DB::table('x_location_data_assignments')->where('assigned_by', $this->admin->id)->count())->toBe(2)
        ->and(DB::table('users')->where('id', $this->admin->id)->exists())->toBeTrue();
    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $this->admin->id)->delete()))->toThrow(QueryException::class);
});

it('restricts location deletion for owned attendance rows even without journal entries', function (string $table) {
    DB::table($table)->where('id', $table === 'x_att_lists' ? $this->list : $this->template)
        ->update(['ext_location_id' => $this->target->id]);
    expect(fn () => DB::transaction(fn () => DB::table('x_locations')->where('id', $this->target->id)->delete()))
        ->toThrow(QueryException::class)
        ->and(DB::table($table)->where('ext_location_id', $this->target->id)->count())->toBe(1);
})->with(['x_att_lists', 'x_att_templates']);

it('preserves global admin visibility of remaining null lists and denies non-global access', function () {
    $list = AttendanceList::withoutGlobalScopes()->findOrFail($this->list);
    expect(AttendanceService::canSee($this->admin, $list))->toBeTrue()
        ->and(AttendanceService::canSee($this->creator, $list))->toBeFalse();
    $service = app(AttendanceService::class);
    expect($service->visibleTo($this->admin)->whereKey($this->list)->exists())->toBeTrue()
        ->and($service->visibleTo($this->creator)->whereKey($this->list)->exists())->toBeFalse();
});

it('rolls back the whole batch on a database failure and does not print private SQL parameters', function () {
    $hash = ($this->previewPlan)('Privater Prüfgrund')['hash'];
    DB::table('x_location_data_assignments')->insert([
        'target_table' => 'x_att_templates', 'target_id' => $this->template, 'location_id' => $this->target->id,
        'assigned_by' => $this->admin->id, 'reason' => 'Frühere Prüfung', 'assigned_at' => now(),
        'batch_id' => (string) Str::uuid(), 'state_hash' => $hash,
    ]);
    expect(Artisan::call(AssignAttendanceCommand::class, [
        'plan' => $this->path, '--apply' => true, '--by' => (string) $this->admin->id,
        '--reason' => 'Privater Prüfgrund', '--backup-confirmed' => true, '--expect' => $hash,
    ]))->toBe(1)
        ->and(Artisan::output())->not->toContain('Privater Prüfgrund', 'insert into')
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_att_templates')->where('id', $this->template)->value('ext_location_id'))->toBeNull()
        ->and(DB::table('x_location_data_assignments')->count())->toBe(1);
});

it('upgrades existing no-FK and custom-FK attendance columns while tolerating optional Locations and safe rollback', function () {
    $searchPath = DB::selectOne('SHOW search_path')->search_path;
    DB::statement('CREATE SCHEMA assignment_optional');
    DB::statement('SET LOCAL search_path TO assignment_optional');
    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('modules', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->boolean('is_active');
            $table->boolean('auto_assign');
            $table->timestamps();
        });
        $create = require base_path('extensions/Anwesenheit/database/migrations/2026_12_20_000000_create_anwesenheit_tables.php');
        $restrict = require base_path('extensions/Anwesenheit/database/migrations/2026_12_23_000001_restrict_attendance_location_deletion.php');
        $create->up();
        $restrict->up();
        $restrict->down();
        expect(Schema::hasTable('x_locations'))->toBeFalse()
            ->and(Schema::hasColumn('x_att_lists', 'ext_location_id'))->toBeTrue()
            ->and(collect(Schema::getForeignKeys('x_att_lists'))->where('name', 'x_att_lists_ext_location_id_foreign'))->toHaveCount(0);
        Schema::create('x_locations', fn (Blueprint $table) => $table->id());
        $restrict->down();
        expect(collect(Schema::getForeignKeys('x_att_lists'))->where('columns', ['ext_location_id']))->toHaveCount(0);
        Schema::table('x_att_templates', fn (Blueprint $table) => $table->foreign('ext_location_id', 'legacy_template_location')
            ->references('id')->on('x_locations')->nullOnDelete());
        $restrict->up();
        foreach (['x_att_lists', 'x_att_templates'] as $table) {
            $foreign = collect(Schema::getForeignKeys($table))->firstWhere('name', $table.'_ext_location_id_foreign');
            expect($foreign['on_delete'])->toBe('restrict');
            expect(collect(Schema::getForeignKeys($table))->where('columns', ['ext_location_id']))->toHaveCount(1);
        }
        $restrict->down();
        foreach (['x_att_lists', 'x_att_templates'] as $table) {
            $foreign = collect(Schema::getForeignKeys($table))->firstWhere('name', $table.'_ext_location_id_foreign');
            expect($foreign['on_delete'])->toBe('set null');
        }
    } finally {
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $searchPath]);
    }
});

it('binds approval to the exact operator and trimmed reason', function (string $change) {
    $hash = ($this->previewPlan)()['hash'];
    expect(($this->previewPlan)('  Bestand und historische Verweise geprüft  ')['hash'])->toBe($hash);
    $by = $this->admin->id;
    $reason = 'Bestand und historische Verweise geprüft';
    if ($change === 'operator') {
        $by = User::factory()->create(['name' => 'Gisela Gemeinde', 'is_admin' => true])->id;
    } else {
        $reason = 'Anderer Prüfgrund';
    }
    expect(fn () => $this->assignment->run($this->path, true, $by, $reason, $hash, true))
        ->toThrow(RuntimeException::class, 'weichen ab')
        ->and(DB::table('x_location_data_assignments')->count())->toBe(0)
        ->and(DB::table('x_att_lists')->where('id', $this->list)->value('ext_location_id'))->toBeNull();
})->with(['operator', 'reason']);

it('hides unassigned templates from non-global prefill and template administration', function () {
    $member = $this->creator->refresh();
    $member->moduleAccess()->create(['module' => 'anwesenheit']);
    $this->actingAs($member);
    $local = DB::table('x_att_templates')->insertGetId(['name' => 'Südvorlage', 'ext_location_id' => $this->target->id]);
    $foreign = DB::table('x_att_templates')->insertGetId(['name' => 'Nordvorlage', 'ext_location_id' => $this->other->id]);
    $service = app(AttendanceService::class);
    expect(array_column($service->prefillOptions($member), 'key'))->toContain('tpl:'.$local)
        ->not->toContain('tpl:'.$this->template, 'tpl:'.$foreign)
        ->and($service->prefill($member, 'tpl:'.$this->template))->toBeNull()
        ->and($service->prefill($member, 'tpl:'.$foreign))->toBeNull()
        ->and($service->prefill($member, 'tpl:'.$local)['template_id'])->toBe($local);
    DB::table('x_location_user')->insert(['user_id' => $member->id, 'location_id' => $this->target->id, 'role' => 'admin']);
    expect(TemplateResource::getEloquentQuery()->pluck('id')->all())->toBe([$local]);
    ($this->applyPlan)();
    expect($service->prefill($member, 'tpl:'.$this->template)['template_id'])->toBe($this->template)
        ->and(TemplateResource::getEloquentQuery()->pluck('id')->all())->toEqualCanonicalizing([$local, $this->template]);
});

it('retains global admin access to every template including unassigned personal recipients', function () {
    $foreign = DB::table('x_att_templates')->insertGetId(['name' => 'Nordvorlage', 'ext_location_id' => $this->other->id]);
    $service = app(AttendanceService::class);
    $this->actingAs($this->creator->refresh());
    expect(array_column($service->prefillOptions($this->admin), 'key'))->toContain('tpl:'.$this->template, 'tpl:'.$foreign)
        ->and($service->prefill($this->admin, 'tpl:'.$this->template)['recipients'])->toBe('vorlage@example.com')
        ->and($service->prefill($this->admin, 'tpl:'.$this->template)['leader_id'])->toBe($this->leader->id);
    $this->actingAs($this->admin);
    expect(TemplateResource::getEloquentQuery()->pluck('id')->all())->toEqualCanonicalizing([$this->template, $foreign]);
});

it('preserves template prefill behavior when Locations is off', function () {
    config(['locations.enabled' => false]);
    $member = $this->creator->refresh();
    $this->actingAs($member);
    $service = app(AttendanceService::class);
    expect(array_column($service->prefillOptions($member), 'key'))->toContain('tpl:'.$this->template)
        ->and($service->prefill($member, 'tpl:'.$this->template)['recipients'])->toBe('vorlage@example.com')
        ->and(TemplateResource::getEloquentQuery()->whereKey($this->template)->exists())->toBeTrue();
});

it('keeps Locations journal migration independent of optional attendance tables', function () {
    $searchPath = DB::selectOne('SHOW search_path')->search_path;
    DB::statement('CREATE SCHEMA assignment_no_attendance');
    DB::statement('SET LOCAL search_path TO assignment_no_attendance');
    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('x_locations', fn (Blueprint $table) => $table->id());
        $history = require base_path('extensions/Locations/database/migrations/2026_12_23_000000_create_location_data_assignments.php');
        $restrict = require base_path('extensions/Anwesenheit/database/migrations/2026_12_23_000001_restrict_attendance_location_deletion.php');
        $restrict->up();
        $history->up();
        expect(Schema::hasTable('x_location_data_assignments'))->toBeTrue()
            ->and(Schema::hasTable('x_att_lists'))->toBeFalse()
            ->and(Schema::hasTable('x_att_templates'))->toBeFalse();
        $restrict->down();
    } finally {
        DB::select('SELECT set_config(?, ?, true)', ['search_path', $searchPath]);
    }
});
