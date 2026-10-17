<?php

use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Gefahrstoffe\src\Livewire\ImportCatalog;
use Modules\Gefahrstoffe\src\Models\Material;
use Modules\Gefahrstoffe\src\Services\MaterialImport;

beforeEach(function () {
    Module::where('key', 'gefahrstoffe')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->manager = User::factory()->create();
    $this->manager->moduleAccess()->create(['module' => 'gefahrstoffe', 'role' => 'gefahrstoffbeauftragter']);
    $this->member = User::factory()->create();
    $this->member->moduleAccess()->create(['module' => 'gefahrstoffe']);
});

test('CSV preview validates all rows and import records provenance', function () {
    $csv = "UN-Nummer;Name;ADR-Klasse;Gefahrzettel;GHS-Piktogramme\n1203;Benzin;3;3|3+;GHS02|GHS07";
    $import = app(MaterialImport::class);

    expect($import->preview($csv))->toMatchArray([
        ['line' => 2, 'un_number' => '1203', 'name' => 'Benzin', 'action' => 'new', 'label' => 'wird hinzugefügt', 'errors' => []],
    ]);

    expect($import->apply($csv, 'Quelle Muster', '2026-01', 'Dokument A', $this->manager->id))
        ->toBe(['created' => 1, 'updated' => 0]);

    $material = Material::where('un_number', '1203')->firstOrFail();
    expect($material->name)->toBe('Benzin')
        ->and($material->hazard_labels)->toBe(['3', '3+'])
        ->and($material->ghs_pictograms)->toBe(['GHS02', 'GHS07'])
        ->and($material->source_name)->toBe('Quelle Muster')
        ->and($material->source_version)->toBe('2026-01')
        ->and($material->source_reference)->toBe('Dokument A')
        ->and($material->imported_by)->toBe($this->manager->id);
    expect(ActivityLog::query()->where('event', 'imported')->where('subject_type', 'Gefahrstoffdatenbank')->where('user_id', $this->manager->id)->exists())->toBeTrue();
});

test('JSON imports update matching UN numbers and preserve records omitted from later imports', function () {
    $import = app(MaterialImport::class);
    $first = json_encode(['materials' => [
        ['un_number' => '1203', 'name' => 'Benzin'],
        ['un_number' => '1202', 'name' => 'Dieselkraftstoff'],
    ]], JSON_THROW_ON_ERROR);
    $import->apply($first, 'Katalog A', '1', null, $this->manager->id);

    $update = json_encode([
        ['un' => '1203', 'name' => 'Benzin (aktualisiert)', 'synonyms' => ['Motor spirit', 'Petrol']],
    ], JSON_THROW_ON_ERROR);
    expect($import->preview($update)[0]['action'])->toBe('update')
        ->and($import->apply($update, 'Katalog B', '2', null, $this->manager->id))
        ->toBe(['created' => 0, 'updated' => 1]);

    expect(Material::where('un_number', '1203')->firstOrFail()->synonyms)->toBe('Motor spirit | Petrol')
        ->and(Material::where('un_number', '1202')->exists())->toBeTrue();
});

test('duplicate and malformed rows block the entire import', function () {
    $import = app(MaterialImport::class);
    $duplicates = "UN-Nummer,Name\n1203,Benzin\n1203,Motorbenzin";
    expect($import->preview($duplicates)[1]['action'])->toBe('invalid');
    expect(fn () => $import->apply($duplicates, 'Quelle', null, null, $this->manager->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(Material::count())->toBe(0);

    $invalid = "UN-Nummer,Name\n12,Benzin";
    expect($import->preview($invalid)[0]['action'])->toBe('invalid');
});

test('only authorized module users can browse and import', function () {
    $this->actingAs($this->member)->get(route('gefahrstoffe.index'))->assertOk();
    $this->actingAs($this->member)->get(route('gefahrstoffe.import'))->assertForbidden();
    $this->actingAs($this->manager)->get(route('gefahrstoffe.import'))->assertOk()->assertSeeLivewire(ImportCatalog::class);

    $this->actingAs(User::factory()->create())->get(route('gefahrstoffe.index'))->assertForbidden();

    Module::where('key', 'gefahrstoffe')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->manager)->get(route('gefahrstoffe.index'))->assertNotFound();
});

test('the module provides a JSON catalogue for offline caching', function () {
    Material::create([
        'un_number' => '1203',
        'name' => 'Benzin',
        'source_name' => 'Quelle Muster',
        'imported_at' => now(),
    ]);

    $this->actingAs($this->member)->get(route('gefahrstoffe.catalogue'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store')
        ->assertJsonPath('materials.0.un_number', '1203')
        ->assertJsonPath('materials.0.source_name', 'Quelle Muster');
});
