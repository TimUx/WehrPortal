<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\MaterialCatalog;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Livewire\SituationLog;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\Material;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Models\SituationMessage;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Einsatzbericht\src\Services\SituationService;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $this->chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'einsatzbericht']);

    $this->r = app(ReportService::class);
    $this->report = $this->r->create($this->leader);
    $this->r->update($this->leader, $this->report, ['keyword' => 'FEU 2', 'address' => 'Dorfstraße 1, Musterstadt', 'category' => 'brand_mittel']);
});

// ─── Lagemeldungen ───────────────────────────────────────────────────────────

test('Kameraden see running Einsätze and send Lagemeldungen with photo while it runs', function () {
    $this->actingAs($this->member)->get('/einsatzbericht')->assertOk()->assertSee('Laufende Einsätze')->assertSee('FEU 2');
    $this->actingAs($this->member)->get('/einsatzbericht/'.$this->report->id.'/lage')->assertOk()->assertSee('Lagemeldungen · FEU 2');
    $this->actingAs($this->member)->get('/einsatzbericht/'.$this->report->id)->assertForbidden();     // not the full report

    Livewire::actingAs($this->member)->test(SituationLog::class, ['report' => $this->report])
        ->set('text', 'Dachstuhl brennt in voller Ausdehnung')
        ->set('important', true)
        ->set('photos', [UploadedFile::fake()->image('lage.jpg', 3000, 2000)])
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Lagemeldung gesendet')
        ->assertSee('Dachstuhl brennt');

    $message = SituationMessage::sole();
    expect($message)->user_id->toBe($this->member->id)->important->toBeTrue()
        ->and($message->photos)->toHaveCount(1)
        ->and($message->photos->first()->mime)->toBe('image/webp');

    // Scaled down on the server
    [$w, $h] = getimagesizefromstring(base64_decode($message->photos->first()->data));
    expect(max($w, $h))->toBe(1600);

    $this->actingAs($this->member)->get(route('einsatzbericht.photo', $message->photos->first()))->assertOk()->assertHeader('Content-Type', 'image/webp');
});

test('important messages come first; Einsatzleiter mark them, authors delete their own', function () {
    $s = app(SituationService::class);
    $first = $s->post($this->member, $this->report, 'Erste Meldung', false);
    $this->travel(1)->minutes();
    $second = $s->post($this->member, $this->report, 'Zweite Meldung', false);

    expect(fn () => $s->toggleImportant($this->member, $first))->toThrow(ValidationException::class);
    $s->toggleImportant($this->leader, $first);
    expect($this->report->messages()->pluck('text')->all())->toBe(['Erste Meldung', 'Zweite Meldung']);

    $other = User::factory()->create();
    $other->moduleAccess()->create(['module' => 'einsatzbericht']);
    expect(fn () => $s->delete($other, $second))->toThrow(ValidationException::class);
    $s->delete($this->member, $second);
    expect(SituationMessage::count())->toBe(1);
});

test('after the Einsatzende only Einsatzleiter and Wehrführung see and write Lagemeldungen', function () {
    app(SituationService::class)->post($this->member, $this->report, 'Lage unverändert', false);
    $this->r->update($this->leader, $this->report, ['ended_at' => Carbon::now()]);
    $this->report->refresh();

    $this->actingAs($this->member)->get('/einsatzbericht/'.$this->report->id.'/lage')->assertForbidden();
    $this->actingAs($this->member)->get(route('einsatzbericht.photo', 1))->assertNotFound();
    expect(fn () => app(SituationService::class)->post($this->member, $this->report, 'zu spät', false))->toThrow(ValidationException::class);

    app(SituationService::class)->post($this->leader, $this->report, 'Nachtrag Einsatzleiter', false);
    $this->actingAs($this->leader)->get('/einsatzbericht/'.$this->report->id)->assertOk()->assertSee('Lage unverändert')->assertSee('Nachtrag Einsatzleiter');
});

test('a message needs text or photo and at most three photos', function () {
    Livewire::actingAs($this->member)->test(SituationLog::class, ['report' => $this->report])
        ->call('send')->assertHasErrors('message')
        ->set('photos', array_map(fn ($i) => UploadedFile::fake()->image("f$i.jpg"), range(1, 4)))
        ->call('send')->assertHasErrors('photos');
});

test('Lagemeldungen with photos are in the printed report', function () {
    app(SituationService::class)->post($this->member, $this->report, 'Riegelstellung aufgebaut', true, [UploadedFile::fake()->image('a.jpg', 400, 300)]);

    $this->actingAs($this->leader)->get('/einsatzbericht/'.$this->report->id.'/druck')->assertOk()
        ->assertSee('Lagemeldungen (Einsatztagebuch)')->assertSee('Riegelstellung aufgebaut')->assertSee('data:image/webp;base64,', false);
});

// ─── Verbrauchsmaterial ──────────────────────────────────────────────────────

test('material is taken from the catalog or typed freely, and its quantity can be changed', function () {
    $foam = Material::where('name', 'Schaummittel')->first();

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $this->report])
        ->set('newMaterial.material', (string) $foam->id)
        ->set('newMaterial.quantity', '12,5')
        ->call('addMaterial')
        ->assertHasNoErrors()
        ->set('newMaterial.free', true)
        ->set('newMaterial.name', 'Feuerlöscher-Füllung')
        ->set('newMaterial.category', 'loeschmittel')
        ->set('newMaterial.unit', 'kilogramm')
        ->set('newMaterial.quantity', '6')
        ->call('addMaterial')
        ->assertSee('Feuerlöscher-Füllung');

    $items = $this->report->materials()->get();
    expect($items)->toHaveCount(2)
        ->and($items->firstWhere('name', 'Schaummittel'))->quantity->toBe(12.5)->unit->toBe('liter')->material_id->toBe($foam->id)
        ->and($items->firstWhere('name', 'Feuerlöscher-Füllung'))->material_id->toBeNull();

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $this->report])
        ->call('updateMaterial', $items->firstWhere('name', 'Schaummittel')->id, '20')
        ->call('removeMaterial', $items->firstWhere('name', 'Feuerlöscher-Füllung')->id);

    expect($this->report->materials()->sole()->quantity)->toBe(20.0);
    expect(fn () => $this->r->addMaterial($this->leader, $this->report, $foam->id, 0))->toThrow(ValidationException::class);
    expect(fn () => $this->r->addMaterial($this->member, $this->report, $foam->id, 1))->toThrow(ValidationException::class);
});

test('the consumption of a year is evaluated by category and material', function () {
    $foam = Material::where('name', 'Schaummittel')->first();
    $this->r->addMaterial($this->leader, $this->report, $foam->id, 10);
    $this->r->update($this->leader, $this->report, ['commander_id' => $this->leader->id]);
    $this->r->assign($this->leader, $this->r->addVehicle($this->leader, $this->report, null), $this->member);
    $this->r->close($this->leader, $this->report->refresh());

    $other = $this->r->create($this->leader);
    $this->r->addMaterial($this->leader, $other, $foam->id, 5);   // still a draft, does not count

    $consumption = $this->r->consumption(now('Europe/Berlin')->year);
    expect($consumption['loeschmittel']['items']->first())->toMatchArray(['name' => 'Schaummittel', 'quantity' => 10.0, 'reports' => 1]);

    Livewire::actingAs($this->chief)->test(Statistics::class)->assertSee('Verbrauchsmaterial')->assertSee('10 Liter');
});

test('admins keep the catalog; used materials are deactivated instead of deleted', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($this->chief)->get('/einsatzbericht/material')->assertForbidden();
    $this->actingAs($admin)->get('/einsatzbericht/material')->assertOk()->assertSee('Material-Katalog')->assertSee('Schaummittel (Liter)');

    Livewire::actingAs($admin)->test(MaterialCatalog::class)
        ->set('name', 'Löschwasserzusatz')->set('category', 'loeschmittel')->set('unit', 'liter')
        ->call('save')->assertHasNoErrors()
        ->set('name', 'Löschwasserzusatz')->set('unit', 'liter')->call('save')->assertHasErrors('name');

    $foam = Material::where('name', 'Schaummittel')->first();
    $this->r->addMaterial($this->leader, $this->report, $foam->id, 1);
    Livewire::actingAs($admin)->test(MaterialCatalog::class)->call('delete', $foam->id)->assertSee('deaktiviert');
    expect($foam->refresh()->is_active)->toBeFalse()->and(Material::where('name', 'Löschwasserzusatz')->exists())->toBeTrue();
});

// ─── map ─────────────────────────────────────────────────────────────────────

test('the point of the Einsatzstelle is set and moved from the map', function () {
    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $this->report])
        ->assertSee('einsatzMap', false)
        ->call('setLocation', 51.1657123, 10.4515456)
        ->assertHasNoErrors();

    expect($this->report->refresh())->latitude->toBe(51.1657123)->longitude->toBe(10.4515456)
        ->and($this->report->navigationUrl())->toBe('https://www.google.com/maps/dir/?api=1&destination=51.1657123,10.4515456');

    expect(fn () => $this->r->setLocation($this->leader, $this->report, 95.0, 10.0))->toThrow(ValidationException::class);
    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $this->report])->call('clearLocation');
    expect($this->report->refresh()->latitude)->toBeNull();
});

test('the Jahresstatistik shows a map of all Einsätze with coordinates', function () {
    $this->r->update($this->leader, $this->report, ['commander_id' => $this->leader->id, 'latitude' => 51.17, 'longitude' => 10.45]);
    $this->r->assign($this->leader, $this->r->addVehicle($this->leader, $this->report, null), $this->member);
    $this->r->close($this->leader, $this->report->refresh());

    Livewire::actingAs($this->chief)->test(Statistics::class)
        ->assertSee('Karte der Einsätze (1 mit Koordinaten)')
        ->assertViewHas('points', fn ($points) => $points->first()['title'] === 'FEU 2');
});
