<?php

use App\Models\Module;
use Extensions\Dateien\Models\AuditEvent;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Notifications\SharedWithYouNotification;
use Extensions\Dateien\Services\FileService;
use Extensions\Dateien\Services\Maintenance;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/helpers.php';

beforeEach(function () {
    Module::where('key', 'dateien')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Storage::fake('dateien');
    $this->admin = filesMember('Ada Atemschutz', admin: true);
    $this->member = filesMember();
    $this->files = app(FileService::class);
    $this->space = $this->files->createSpace($this->admin, ['name' => 'Wehrführung', 'ext_location_id' => Location::default()->id]);
    $this->node = $this->files->upload($this->admin, $this->space, null, filesUpload());
});

it('serves byte ranges', function () {
    $this->actingAs($this->admin)->get(route('dateien.download', ['node' => $this->node->id, 'version' => $this->node->versions()->sole()->id]), ['Range' => 'bytes=0-4'])
        ->assertStatus(206)->assertHeader('content-range', 'bytes 0-4/13');
});

it('uploads through the endpoint with rights checks', function () {
    $this->actingAs($this->member)->postJson(route('dateien.upload'), ['file' => filesUpload('Neue Fassung', 'neu.txt'), 'space' => $this->space->id])->assertForbidden();
    $this->files->setPermission($this->admin, $this->space, 'user', $this->member->id, 'edit');
    $this->actingAs($this->member)->postJson(route('dateien.upload'), ['file' => filesUpload('Neue Fassung', 'neu.txt'), 'space' => $this->space->id])->assertCreated();
    $this->actingAs($this->member)->postJson(route('dateien.upload'), [
        'file' => filesUpload('Datei im Unterordner', 'unterlage.txt'),
        'space' => $this->space->id,
        'path' => 'Ausbildung/2026',
    ])->assertCreated();
    $uploaded = Node::query()->where('name', 'unterlage.txt')->sole();
    expect($uploaded->parent->name)->toBe('2026')
        ->and($uploaded->parent->parent->name)->toBe('Ausbildung');
    $this->actingAs($this->member)->postJson(route('dateien.upload'), [
        'file' => filesUpload('Ungültiger Pfad', 'ungueltig.txt'),
        'space' => $this->space->id,
        'path' => '../fremd',
    ])->assertUnprocessable();
    $this->actingAs($this->member)->postJson(route('dateien.upload'), [
        'directories' => ['Leere Ordner', 'Leere Ordner/Unterordner'],
        'space' => $this->space->id,
    ])->assertCreated()->assertJson(['created' => 2]);
    $this->actingAs($this->member)->postJson(route('dateien.upload'), ['file' => filesUpload('Zweite Fassung'), 'node' => $this->node->id, 'expected_version' => 1])
        ->assertOk()->assertJson(['version' => 2]);
});

it('finds only readable entries by name', function () {
    expect($this->files->search($this->member, 'dienst'))->toHaveCount(0);
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    expect($this->files->search($this->member, 'DIENST')->pluck('id')->all())->toBe([$this->node->id])
        ->and($this->files->search($this->member, '%'))->toHaveCount(0);
});

it('notifies new recipients of a share', function () {
    Notification::fake();
    $this->files->setPermission($this->admin, $this->node, 'user', $this->member->id, 'read');
    Notification::assertSentTo($this->member, SharedWithYouNotification::class);
    Notification::assertNotSentTo($this->admin, SharedWithYouNotification::class);
});

it('reports missing files and orphans and deletes only orphans', function () {
    $disk = Storage::disk('dateien');
    $orphan = '11111111-1111-1111-1111-111111111111/22222222-2222-2222-2222-222222222222';
    $disk->put($orphan, 'verwaist');
    touch($disk->path($orphan), now()->subDay()->getTimestamp());
    $report = app(Maintenance::class)->reconcile(true);
    expect($report['orphans'])->toHaveCount(1)->and($report['missing'])->toBeEmpty();
    $this->artisan('dateien:reconcile')->assertFailed();
    app(Maintenance::class)->deleteOrphans($this->admin);
    expect($disk->exists($orphan))->toBeFalse()->and($disk->exists($this->node->versions()->sole()->storage_key))->toBeTrue();
    $this->artisan('dateien:reconcile')->assertSuccessful();
});

it('prunes only old audit events and records the prune', function () {
    AuditEvent::create(['action' => 'node.created', 'metadata' => []]);
    DB::table('x_files_audit_events')->insert(['action' => 'alt', 'metadata' => '{}', 'created_at' => now()->subDays(800)]);
    expect(app(Maintenance::class)->pruneAudit())->toBe(1)
        ->and(AuditEvent::where('action', 'alt')->exists())->toBeFalse()
        ->and(AuditEvent::where('action', 'audit.pruned')->exists())->toBeTrue();
    expect(fn () => AuditEvent::query()->delete())->toThrow(Exception::class);
});
