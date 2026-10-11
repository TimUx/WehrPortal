<?php

use App\Filament\Resources\UserResource;
use App\Livewire\Dashboard\Home;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\MemberMissions;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Livewire\ReportList;
use Modules\Einsatzbericht\src\Livewire\Settings;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-05-10 20:00', 'Europe/Berlin'));

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $this->chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->member->moduleAccess()->create(['module' => 'einsatzbericht']);
    $this->tim = User::factory()->create(['name' => 'Tim Tanklösch']);

    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->tlf = Vehicle::factory()->create(['name' => 'TLF 3000', 'is_active' => true]);
    $this->r = app(ReportService::class);
});

/** A complete report with crew: 1 Gruppenführer, 1 Maschinist, 2 Mannschaft (one AGT), 1 in Bereitschaft */
function fullReport(): Report
{
    $t = test();
    $report = $t->r->create($t->leader, Carbon::parse('2027-05-10 19:12', 'Europe/Berlin'));
    $t->r->update($t->leader, $report, [
        'keyword' => 'FEU 2', 'address' => 'Dorfstraße 1, Musterstadt', 'category' => 'brand_mittel',
        'arrived_at' => Carbon::parse('2027-05-10 19:20', 'Europe/Berlin'), 'ended_at' => Carbon::parse('2027-05-10 21:12', 'Europe/Berlin'),
    ]);
    $hlf = $t->r->addVehicle($t->leader, $report, $t->hlf->id);
    $t->r->assign($t->leader, $hlf, $t->leader, 'gruppenfuehrer');
    $t->r->assign($t->leader, $hlf, $t->tim, 'maschinist');
    $t->r->assign($t->leader, $hlf, $t->member, 'angriffstrupp', true);
    $t->r->assign($t->leader, $hlf, $t->chief, 'wassertrupp');
    $standby = $t->r->addVehicle($t->leader, $report, null);
    $t->r->assign($t->leader, $standby, User::factory()->create(['name' => 'Klara Kübel']));

    return $report->refresh();
}

// ─── access ──────────────────────────────────────────────────────────────────

test('only Einsatzleiter, Wehrführung and admins open reports; Kameraden get their own Einsätze', function () {
    $report = $this->r->create($this->leader);

    $this->actingAs($this->leader)->get('/einsatzbericht')->assertOk()->assertSee('Einsätze 2027');
    $this->actingAs($this->leader)->get('/einsatzbericht/'.$report->id)->assertOk()->assertSee('Einsatz 2027/001');
    $this->actingAs($this->member)->get('/einsatzbericht')->assertOk()->assertSee('Laufende Einsätze');
    $this->actingAs($this->member)->get('/einsatzbericht/'.$report->id)->assertForbidden();
    $this->actingAs($this->member)->get('/einsatzbericht/statistik')->assertForbidden();
    $this->actingAs($this->leader)->get('/einsatzbericht/statistik')->assertForbidden();
    $this->actingAs($this->chief)->get('/einsatzbericht/statistik')->assertOk();
    $this->actingAs($this->tim)->get('/personalakte/einsaetze')->assertForbidden();   // no module access
});

test('new reports get running numbers per year', function () {
    expect($this->r->create($this->leader)->number)->toBe('2027/001')
        ->and($this->r->create($this->leader)->number)->toBe('2027/002')
        ->and($this->r->create($this->leader, Carbon::parse('2028-01-02 03:00', 'Europe/Berlin'))->number)->toBe('2028/001');
    expect(fn () => $this->r->create($this->member))->toThrow(ValidationException::class);

    Livewire::actingAs($this->leader)->test(ReportList::class)->call('create')->assertRedirect(route('einsatzbericht.show', Report::latest('id')->first()));
});

// ─── crew and Stärke ─────────────────────────────────────────────────────────

test('crew per vehicle gives the Stärke; the Bereitschaft counts as participation, not as Stärke', function () {
    $report = fullReport();

    expect(ReportService::reportStrength($report)['label'])->toBe('0/1/3/4')
        ->and($report->crew()->count())->toBe(5);

    // Someone on another vehicle moves over
    $tlf = $this->r->addVehicle($this->leader, $report, $this->tlf->id);
    $this->r->assign($this->leader, $tlf, $this->member, 'angriffstrupp', true);
    expect($report->crew()->where('user_id', $this->member->id)->sole()->report_vehicle_id)->toBe($tlf->id)
        ->and($report->crew()->count())->toBe(5);

    // No Atemschutz in the Bereitschaft; a vehicle only once
    $standby = $report->vehicles()->where('is_standby', true)->first();
    $crew = $this->r->assign($this->leader, $standby, $this->tim, 'einsatzkraft', true);
    expect($crew->breathing_apparatus)->toBeFalse();
    expect(fn () => $this->r->addVehicle($this->leader, $report, $this->hlf->id))->toThrow(ValidationException::class, 'schon eingetragen');
});

test('the editor saves the blocks, vehicles and crew', function () {
    $report = $this->r->create($this->leader);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.keyword', 'TH 1')
        ->set('form.address', 'Hauptstraße 5, Musterstadt')
        ->set('form.category', 'th')
        ->set('form.arrived_at', '2027-05-10T20:10')
        ->set('form.rescued', '1')
        ->set('form.police', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('report-saved', message: 'Entwurf gespeichert. Noch offen: Mindestens eine Einsatzkraft.', field: 'crew', warn: true)
        ->set('newVehicle', (string) $this->hlf->id)->call('addVehicle')
        ->assertSee('HLF 20');

    $vehicle = $report->vehicles()->first();
    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set("newCrew.{$vehicle->id}.user", (string) $this->member->id)
        ->set("newCrew.{$vehicle->id}.function", 'angriffstrupp')
        ->set("newCrew.{$vehicle->id}.ba", true)
        ->call('addCrew', $vehicle->id)
        ->assertSee('Hanna Hydrant')
        ->assertSee('Stärke 0/0/1/1');

    $report->refresh();
    expect($report->keyword)->toBe('TH 1')->and($report->category)->toBe('th')->and($report->rescued)->toBe(1)->and($report->police)->toBeTrue()
        ->and($report->arrived_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'))->toBe('2027-05-10 20:10')
        ->and($report->crew()->sole()->breathing_apparatus)->toBeTrue();
});

test('saving reports "Gespeichert" and jumps to the first empty mandatory field', function () {
    $report = fullReport();

    $editor = Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.keyword', '')
        ->call('save')
        ->assertDispatched('report-saved', field: 'keyword', warn: true);

    $editor->set('form.keyword', 'FEU 2')->set('form.alarmed_at', '2027-05-10T19:12')->set('form.commander_id', (string) $this->leader->id)
        ->call('save')
        ->assertDispatched('report-saved', message: 'Entwurf gespeichert.', field: null, warn: false);
});

test('an invalid input is not saved and the page jumps to it', function () {
    $report = $this->r->create($this->leader);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('form.keyword', 'TH 1')
        ->set('form.ended_at', 'gestern')
        ->call('save')
        ->assertHasErrors('form.ended_at')
        ->assertDispatched('report-saved', field: 'ended_at', warn: true);

    expect($report->refresh()->keyword)->toBeNull();
});

test('closing with empty mandatory fields jumps to the first one', function () {
    $report = $this->r->create($this->leader);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->call('close')
        ->assertDispatched('report-saved', message: 'Nicht abgeschlossen – es fehlen noch Pflichtfelder.', field: 'keyword');

    expect($report->refresh()->status)->toBe('draft');
});

// ─── states ──────────────────────────────────────────────────────────────────

test('closing needs the mandatory fields the Wehrführung chose', function () {
    $report = $this->r->create($this->leader);

    expect(fn () => $this->r->close($this->leader, $report))->toThrow(ValidationException::class, 'Einsatzstichwort');

    Livewire::actingAs($this->chief)->test(Settings::class)->set('required', ['keyword'])->call('save')->assertSee('Pflichtfelder gespeichert');
    $this->r->update($this->leader, $report, ['keyword' => 'FEU 1']);
    $this->r->close($this->leader, $report->refresh());

    expect($report->refresh()->status)->toBe('closed');
});

test('a closed report is locked for the Einsatzleiter; the Wehrführung releases, corrects with history and can reopen', function () {
    $report = fullReport();
    $this->r->close($this->leader, $report);

    expect(fn () => $this->r->update($this->leader, $report->refresh(), ['keyword' => 'FEU 3']))->toThrow(ValidationException::class, 'Wehrführung')
        ->and(fn () => $this->r->release($this->leader, $report))->toThrow(ValidationException::class);

    $this->actingAs($this->chief);
    $this->r->release($this->chief, $report);
    $this->r->update($this->chief, $report->refresh(), ['keyword' => 'FEU 3']);

    expect($report->refresh())->status->toBe('released')->keyword->toBe('FEU 3');
    $change = ActivityLog::where('subject_type', Report::class)->where('subject_id', $report->id)->where('event', 'updated')->latest('id')->first();
    expect($change->user_id)->toBe($this->chief->id)->and($change->changes['keyword'])->toBe(['FEU 2', 'FEU 3']);

    Livewire::actingAs($this->chief)->test(ReportEditor::class, ['report' => $report])
        ->assertSee('Freigegeben')->assertSee('Änderungen')
        ->call('reopen')->assertSee('wieder offen (aktuell)');
    expect($report->refresh()->status)->toBe('active');
});

test('the editor walks through close and release', function () {
    $report = fullReport();

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->call('close')->assertHasNoErrors()->assertSee('Bericht abgeschlossen');
    Livewire::actingAs($this->chief)->test(Home::class)->assertSee('1 Bericht wartet auf Freigabe');
    Livewire::actingAs($this->chief)->test(ReportEditor::class, ['report' => $report])
        ->call('release')->assertSee('Bericht freigegeben');

    expect($report->refresh()->status)->toBe('released')->and($report->released_by)->toBe($this->chief->id);
});

// ─── Personalakte, statistics, print ─────────────────────────────────────────

test('the Personalakte shows the own Einsätze with counters; drafts do not count', function () {
    $report = fullReport();
    $this->actingAs($this->member)->get('/personalakte/einsaetze')->assertOk()->assertSee('Du hast noch keine Einsätze in der App.');

    $this->r->close($this->leader, $report);

    $this->actingAs($this->member)->get('/personalakte')->assertSee('Einsätze');      // menu item in the Personalakte
    Livewire::actingAs($this->member)->test(MemberMissions::class)
        ->assertViewHas('total', 1)->assertViewHas('breathing', 1)->assertViewHas('minutes', 120)
        ->assertSee('FEU 2')->assertSee('HLF 20 · Angriffstrupp · Atemschutz');

    // Only the Wehrführung sees others
    Livewire::actingAs($this->member)->test(MemberMissions::class, ['member' => $this->tim])->assertForbidden();
    $this->actingAs($this->chief)->get('/einsatzbericht/kameraden')->assertOk()->assertSee('Hanna Hydrant');
    $this->actingAs($this->chief)->get('/einsatzbericht/kameraden/'.$this->member->id)->assertOk()->assertSee('FEU 2');
});

test('the Jahresstatistik counts closed and released reports by type, month and vehicle', function () {
    $this->r->close($this->leader, fullReport());
    $this->r->create($this->leader);   // a draft does not count

    Livewire::actingAs($this->chief)->test(Statistics::class)
        ->assertViewHas('stats', fn ($s) => $s['total'] === 1 && $s['minutes'] === 120 && $s['byMonth'][5] === 1
            && $s['byVehicle']->all() === ['HLF 20' => 1] && $s['byCategory']->first()['label'] === 'Brand – Mittelbrand' && $s['drafts'] === 1)
        ->assertSee('Jahresstatistik 2027');
});

test('the report prints as A4 with all blocks and the Stärke', function () {
    $report = fullReport();

    $this->actingAs($this->leader)->get('/einsatzbericht/'.$report->id.'/druck')->assertOk()
        ->assertSee('Einsatzbericht 2027/001')->assertSee('Drucken / als PDF speichern')
        ->assertSee('Stärke 0/1/3/4')->assertSee('Hanna Hydrant (Angriffstrupp, AS)')->assertSee('Dorfstraße 1, Musterstadt');
    $this->actingAs($this->member)->get('/einsatzbericht/'.$report->id.'/druck')->assertForbidden();
});

test('navigation opens Google Maps with the address', function () {
    $report = fullReport();

    expect($report->navigationUrl())->toBe('https://www.google.com/maps/dir/?api=1&destination=Dorfstra%C3%9Fe%201%2C%20Musterstadt');
});

test('a Kamerad on a report cannot be deleted', function () {
    $report = fullReport();

    expect(UserResource::deleteIfUnreferenced($this->member))->toBeFalse();
});
