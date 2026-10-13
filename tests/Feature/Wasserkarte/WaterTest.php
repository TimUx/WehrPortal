<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Wasserkarte\src\Livewire\Inspections;
use Modules\Wasserkarte\src\Livewire\MapView;
use Modules\Wasserkarte\src\Livewire\SourceDetail;
use Modules\Wasserkarte\src\Livewire\SourceForm;
use Modules\Wasserkarte\src\Models\Source;
use Modules\Wasserkarte\src\Notifications\DefectReportedNotification;
use Modules\Wasserkarte\src\Notifications\DueDigestNotification;
use Modules\Wasserkarte\src\Services\WaterService;

/** Einsatzstelle of the tests; 0.001° latitude are about 111 m */
const WK_LAT = 52.5;
const WK_LNG = 9.7;

beforeEach(function () {
    Module::whereIn('key', ['wasserkarte', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->wart = User::factory()->create(['name' => 'Heike Hydrantenwart']);
    $this->wart->moduleAccess()->create(['module' => 'wasserkarte', 'role' => 'hydrantenwart']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'wasserkarte']);
    $this->w = app(WaterService::class);

    $this->h1 = $this->w->saveSource($this->wart, null, ['number' => 'H-001', 'kind' => 'unterflur', 'latitude' => WK_LAT + 0.002, 'longitude' => WK_LNG, 'location' => 'vor Hauptstraße 12', 'flow_lpm' => '1600', 'pipe_dn' => '100', 'inspection_interval_months' => 12]);
    $this->h2 = $this->w->saveSource($this->wart, null, ['number' => 'H-002', 'kind' => 'ueberflur', 'latitude' => WK_LAT + 0.0005, 'longitude' => WK_LNG, 'location' => 'Schulweg 3']);
    $this->pond = $this->w->saveSource($this->wart, null, ['kind' => 'teich', 'latitude' => WK_LAT + 0.006, 'longitude' => WK_LNG, 'capacity_m3' => '200']);
    $this->far = $this->w->saveSource($this->wart, null, ['number' => 'H-099', 'kind' => 'unterflur', 'latitude' => WK_LAT + 0.05, 'longitude' => WK_LNG]);
});

test('the Hydrantenwart adds a source with position from the map or GPS', function () {
    Livewire::actingAs($this->wart)->test(SourceForm::class)
        ->set('form.kind', 'brunnen')->call('save')->assertHasErrors('latitude')
        ->set('form.latitude', '52,51')->set('form.longitude', '9.71')->set('form.number', 'B-1')->set('form.flow_lpm', '800')
        ->call('save')->assertHasNoErrors()->assertRedirect();

    expect(Source::where('number', 'B-1')->sole())->latitude->toBe(52.51)->kind->toBe('brunnen')->performance()->toBe('800 l/min');
    $this->actingAs($this->hanna)->get(route('wasserkarte.create'))->assertForbidden();
});

test('the nearest sources: usable first, broken ones marked, nothing beyond 1.5 km', function () {
    $this->w->setStatus($this->wart, $this->h2, 'locked', 'Baustelle');

    $nearest = $this->w->nearest(WK_LAT, WK_LNG);
    expect($nearest->pluck('source.id')->all())->toBe([$this->h1->id, $this->pond->id, $this->h2->id])
        ->and($nearest->first()['meters'])->toBeBetween(215, 230);
});

test('a Kamerad reports a defect: the source is defective at once until it is fixed', function () {
    Livewire::actingAs($this->hanna)->test(SourceDetail::class, ['source' => $this->h1])
        ->set('defectDescription', 'Zu')->call('reportDefect')->assertHasErrors('defect_description')
        ->set('defectDescription', 'Deckel festgerostet')->set('defectPhoto', UploadedFile::fake()->image('deckel.jpg', 800, 600))
        ->call('reportDefect')->assertHasNoErrors()->assertSee('Mangel ist gemeldet');

    expect($this->h1->fresh())->status->toBe('defective')->status_note->toBe('Gemeldet: Deckel festgerostet');
    Notification::assertSentTo($this->wart, DefectReportedNotification::class);
    expect($this->w->nearest(WK_LAT, WK_LNG)->first()['source']->id)->toBe($this->h2->id);

    $defect = $this->h1->defects()->sole();
    expect(fn () => $this->w->resolveDefect($this->hanna, $defect, 'gängig gemacht'))->toThrow(ValidationException::class);
    Livewire::actingAs($this->wart)->test(SourceDetail::class, ['source' => $this->h1])->set('resolution', 'Deckel gängig gemacht')->call('resolveDefect', $defect->id);
    expect($this->h1->fresh()->status)->toBe('available')->and($this->h1->events()->count())->toBe(2);
});

test('inspections set the next date and the measured flow; a defect found marks it defective', function () {
    $this->w->recordInspection($this->wart, $this->h1, ['inspected_on' => now('Europe/Berlin')->toDateString(), 'result' => 'passed', 'flow_lpm' => '1450', 'static_pressure_bar' => '4,5']);
    expect($this->h1->fresh())->flow_lpm->toBe(1450)->pressure_bar->toBe(4.5)
        ->next_inspection_on->toDateString()->toBe(now('Europe/Berlin')->addMonths(12)->toDateString());

    expect(fn () => $this->w->recordInspection($this->wart, $this->h2, ['inspected_on' => now('Europe/Berlin')->toDateString(), 'result' => 'defect']))->toThrow(ValidationException::class);
    $this->w->recordInspection($this->wart, $this->h2, ['inspected_on' => now('Europe/Berlin')->toDateString(), 'result' => 'defect', 'notes' => 'kein Wasser']);
    expect($this->h2->fresh()->status)->toBe('defective');
});

test('at the Einsatz: report and Lagemeldungen show the nearest Löschwasser; the map highlights it', function () {
    $leader = User::factory()->create();
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $leader->moduleAccess()->create(['module' => 'wasserkarte']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader);
    $reports->update($leader, $report, ['keyword' => 'FEU 2', 'latitude' => WK_LAT, 'longitude' => WK_LNG]);
    $reports->start($leader, $report);

    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()->assertSee('Löschwasser in der Nähe')->assertSee('H-002 · Überflurhydrant');
    $this->actingAs($leader)->get(route('einsatzbericht.situation', $report))->assertOk()->assertSee('Löschwasser in der Nähe')->assertSee('56 m');

    Livewire::actingAs($this->hanna)->withQueryParams(['einsatz' => $report->id])->test(MapView::class)
        ->assertSee('Nächste Wasserquellen zur Einsatzstelle')->assertSee('H-001');
});

test('the map: "Mein Standort" lists the nearest, a change elsewhere reaches the open map', function () {
    $map = Livewire::actingAs($this->hanna)->test(MapView::class)
        ->call('nearPoint', WK_LAT, WK_LNG)->assertSee('Nächste Wasserquellen zu deinem Standort')
        ->assertDispatched('water-markers');

    $map->call('refresh')->assertNotDispatched('water-markers');
    $this->w->setStatus($this->wart, $this->h1, 'locked', 'Straßensperrung');
    $map->call('refresh')->assertDispatched('water-markers');
});

test('the list of the Wasserversorger is imported; known numbers are updated', function () {
    $csv = "Nummer;Art;Breitengrad;Längengrad;Lage;Durchfluss;DN\nH-001;Unterflur;52,502;9,7;vor Hauptstraße 12;1800;150\nH-010;Überflur;52,51;9,71;Am Markt;1200;100\nkaputt;Unterflur;;;x;;\n";

    Livewire::actingAs($this->wart)->test(Inspections::class)
        ->set('csv', UploadedFile::fake()->createWithContent('hydranten.csv', $csv))->call('import')
        ->assertSee('1 neu, 1 aktualisiert, 1 übersprungen')->assertSee('Zeile 4');

    expect($this->h1->fresh())->flow_lpm->toBe(1800)->pipe_dn->toBe(150)
        ->and(Source::where('number', 'H-010')->sole())->kind->toBe('ueberflur')->inspection_interval_months->toBe(12);
});

test('inspections due: one mail to the Hydrantenwarte; start page card', function () {
    $this->w->recordInspection($this->wart, $this->h1, ['inspected_on' => now('Europe/Berlin')->subMonths(12)->toDateString(), 'result' => 'passed']);
    $this->w->reportDefect($this->hanna, $this->h2, 'Schild fehlt');

    $this->artisan('wasserkarte:remind')->assertSuccessful();
    Notification::assertSentTo($this->wart, DueDigestNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'H-001'));
    Notification::fake();
    $this->artisan('wasserkarte:remind')->assertSuccessful();
    Notification::assertNothingSent();

    Livewire::actingAs($this->wart)->test(Home::class)->assertSee('1 Wasserquelle defekt');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertDontSee('Wasserquelle defekt');
    $this->actingAs($this->wart)->get(route('wasserkarte.inspections'))->assertOk()->assertSee('Schild fehlt');
    $this->actingAs($this->hanna)->get(route('wasserkarte.index'))->assertOk()->assertSee('H-001');
});

test('Saugstellen keep their Saughöhe, also from the import', function () {
    $saug = $this->w->saveSource($this->wart, null, ['number' => 'S-1', 'kind' => 'gewaesser', 'latitude' => WK_LAT, 'longitude' => WK_LNG + 0.01, 'suction_height_m' => '3,5', 'flow_lpm' => '800']);
    expect($saug->suction_height_m)->toBe(3.5)->and($saug->performance())->toBe('800 l/min · Saughöhe 3,5 m');

    $this->w->import($this->wart, "Nummer;Art;lat;lng;Saughöhe\nS-2;Saugstelle;52.51;9.71;2,0");
    expect(Source::where('number', 'S-2')->sole()->suction_height_m)->toBe(2.0);
});

test('the map shows a due Prüfung and filters for it', function () {
    $this->w->recordInspection($this->wart, $this->h1, ['inspected_on' => now('Europe/Berlin')->subMonths(12)->addDays(10)->toDateString(), 'result' => 'passed']);
    expect($this->h1->fresh()->inspectionDue())->toBeTrue()->and($this->h1->fresh()->inspectionOverdue())->toBeFalse();

    $marker = collect(WaterService::markers(Source::active()->get()))->keyBy('id');
    expect($marker[$this->h1->id]['due'])->toBeTrue()->and($marker[$this->h1->id]['info'])->toContain('Prüfung fällig')
        ->and($marker[$this->h2->id]['due'])->toBeFalse();

    Livewire::actingAs($this->hanna)->test(MapView::class)->assertSee('1 mit fälliger Prüfung')
        ->set('status', 'due')->assertSee('vor Hauptstraße 12')->assertDontSee('Schulweg 3');
});

test('a QR label leads to the source; the Prüfnachweis comes as CSV', function () {
    $this->w->recordInspection($this->wart, $this->h1, ['inspected_on' => now('Europe/Berlin')->toDateString(), 'result' => 'passed', 'flow_lpm' => '1450']);

    $this->actingAs($this->wart)->get(route('wasserkarte.label', $this->h1))->assertOk()->assertSee('WK-'.$this->h1->id)->assertSee('H-001');
    $this->get(route('wasserkarte.source', $this->h1))->assertSee('QR-Etikett drucken')->assertSee('von Heike Hydrantenwart');
    $this->get(route('scan.code', 'WK-'.$this->h1->id))->assertRedirect(route('wasserkarte.source', $this->h1->id));
    $this->get(route('scan.code', 'WK-999999'))->assertNotFound();

    $csv = $this->get(route('wasserkarte.inspections.csv'))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->getContent();
    expect($csv)->toContain('Nummer;Art;Lage')->toContain('H-001;Unterflurhydrant;"vor Hauptstraße 12"')->toContain('In Ordnung')->toContain('Heike Hydrantenwart');

    // Kameraden without the role: neither label nor CSV
    $this->actingAs($this->hanna)->get(route('wasserkarte.inspections.csv'))->assertForbidden();
    $this->get(route('wasserkarte.label', $this->h1))->assertForbidden();
    $this->get(route('wasserkarte.source', $this->h1))->assertDontSee('QR-Etikett drucken');
});
