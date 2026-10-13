<?php

use App\Models\Module;
use App\Models\User;
use App\Services\AnnualReportRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Livewire\Deadlines;
use Modules\Fahrzeuge\src\Livewire\Fleet;
use Modules\Fahrzeuge\src\Livewire\VehicleDetail;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Document;
use Modules\Fahrzeuge\src\Models\Profile;
use Modules\Fahrzeuge\src\Models\Record;
use Modules\Fahrzeuge\src\Services\FleetService;
use Modules\Finanzen\src\Models\CostCenter;
use Modules\Finanzen\src\Models\Entry;
use Modules\Kalender\src\Models\Event;
use Modules\Maengelmanagement\src\Livewire\ReportForm;
use Modules\Maengelmanagement\src\Models\Defect;

beforeEach(function () {
    Module::whereIn('key', ['fahrzeuge', 'maengelmanagement', 'kalender', 'finanzen'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->wart = User::factory()->create(['name' => 'Frieda Fahrzeugwart']);
    foreach (['fahrzeuge' => 'fahrzeugwart', 'maengelmanagement' => null, 'kalender' => null, 'finanzen' => null] as $module => $role) {
        $this->wart->moduleAccess()->create(['module' => $module, 'role' => $role]);
    }
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    foreach (['fahrzeuge', 'maengelmanagement'] as $module) {
        $this->hanna->moduleAccess()->create(['module' => $module]);
    }

    $this->f = app(FleetService::class);
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->uvv = $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'UVV-Prüfung (DGUV Grundsatz 314-003)', 'interval_months' => 12,
        'due_on' => now('Europe/Berlin')->addDays(10)->toDateString(), 'steps' => "Bremsanlage\nBeleuchtung und Sondersignalanlage\nSicherung der Beladung"]);
});

function uvvChecklist(array $states): array
{
    return collect(test()->uvv->steps)->map(fn ($step, $i) => ['step' => $step, 'state' => $states[$i] ?? 'ok', 'note' => ($states[$i] ?? 'ok') === 'defect' ? 'Rundumleuchte hinten links ohne Funktion' : null])->all();
}

test('a Prüfung with checklist and signature sets the Einsatzbereitschaft and reports its Mängel', function () {
    expect($this->uvv->steps)->toHaveCount(3);

    // A Mangel: bedingt einsatzbereit, the deadline moves on, the Mangel is in the Mängelmanagement
    $record = $this->f->recordWork($this->wart, $this->hlf, ['deadline_id' => $this->uvv->id, 'kind' => 'inspection', 'done_on' => FleetService::today()->toDateString(),
        'provider' => 'Gerätewart Bernd Blaulicht', 'checklist' => uvvChecklist([1 => 'defect']), 'signature' => signaturePng(), 'signer_name' => 'Bernd Blaulicht']);

    expect($record->result)->toBe('limited')->and($record->signer_name)->toBe('Bernd Blaulicht')
        ->and(Profile::where('vehicle_id', $this->hlf->id)->value('status'))->toBe('limited')
        ->and(Profile::where('vehicle_id', $this->hlf->id)->value('status_source'))->toBe('inspection')
        ->and($this->uvv->refresh()->due_on->toDateString())->toBe(FleetService::today()->addMonths(12)->toDateString());
    $defect = Defect::sole();
    expect($defect->vehicle_id)->toBe($this->hlf->id)->and($defect->title)->toBe('Beleuchtung und Sondersignalanlage')
        ->and($defect->subject())->toBe('HLF 20')->and($defect->description)->toContain('Rundumleuchte');

    // "passed" while a step has a Mangel is refused
    expect(fn () => $this->f->recordWork($this->wart, $this->hlf, ['kind' => 'inspection', 'title' => 'Nachprüfung', 'done_on' => FleetService::today()->toDateString(),
        'result' => 'passed', 'checklist' => uvvChecklist([0 => 'defect'])]))->toThrow(ValidationException::class);

    // The Prüfprotokoll shows steps, result and signature
    $this->actingAs($this->hanna)->get(route('fahrzeuge.record', $record))->assertOk()
        ->assertSee('Prüfprotokoll: UVV-Prüfung')->assertSee('Mangel')->assertSee('Rundumleuchte hinten links')->assertSee('Bernd Blaulicht')->assertSee('data:image/png;base64,', false);

    // A passed Nachprüfung lifts the status the Prüfung had set
    $this->f->recordWork($this->wart, $this->hlf, ['kind' => 'inspection', 'title' => 'Nachprüfung Beleuchtung', 'done_on' => FleetService::today()->toDateString(), 'result' => 'passed']);
    expect(Profile::where('vehicle_id', $this->hlf->id)->value('status'))->toBe('ready');
});

test('a failed Prüfung makes the vehicle nicht einsatzbereit and keeps the deadline open; a manual status is not lifted', function () {
    $due = $this->uvv->due_on->toDateString();
    $this->f->recordWork($this->wart, $this->hlf, ['deadline_id' => $this->uvv->id, 'kind' => 'inspection', 'done_on' => FleetService::today()->toDateString(),
        'result' => 'failed', 'checklist' => uvvChecklist([0 => 'defect'])]);

    expect(Profile::where('vehicle_id', $this->hlf->id)->value('status'))->toBe('unavailable')
        ->and($this->uvv->refresh()->due_on->toDateString())->toBe($due)
        ->and(Defect::sole()->urgency->value)->toBe('high');

    // Set by hand: a passed Prüfung doesn't override the Fahrzeugwart
    $this->f->setStatus($this->wart, $this->hlf, 'unavailable', 'Wassertank undicht');
    $this->f->recordWork($this->wart, $this->hlf, ['deadline_id' => $this->uvv->id, 'kind' => 'inspection', 'done_on' => FleetService::today()->toDateString(), 'checklist' => uvvChecklist([])]);
    expect(Profile::where('vehicle_id', $this->hlf->id)->value('status'))->toBe('unavailable');
});

test('the Fahrzeugprofil documents a Prüfung with its checklist and hands the costs to the Finanzen', function () {
    $center = CostCenter::create(['name' => 'Fahrzeuge', 'code' => '100', 'is_active' => true]);

    Livewire::actingAs($this->wart)->test(VehicleDetail::class, ['vehicle' => $this->hlf])
        ->call('startRecord', $this->uvv->id)
        ->assertSet('checklist.0.step', 'Bremsanlage')->assertSet('record.result', 'passed')
        ->set('checklist.2.state', 'defect')->assertSet('record.result', 'limited')
        ->set('record.cost', '149,90')->set('record.provider', 'Fahrzeugtechnik Musterstadt')->set('record.invoice_number', 'R-2026-118')
        ->set('toFinance', true)->set('costCenterId', (string) $center->id)
        ->call('saveRecord')->assertHasNoErrors()
        ->assertSee('Mangel steht jetzt im Mängelmanagement')->assertSee('als Ausgabe bei den Finanzen eingereicht')
        ->assertSee('Rechnung R-2026-118')->assertSee('mit Mängeln')->assertSee('Offene Mängel')->assertSee('Sicherung der Beladung');

    $entry = Entry::sole();
    expect($entry->amount_cents)->toBe(14990)->and($entry->title)->toBe('HLF 20: UVV-Prüfung (DGUV Grundsatz 314-003)')
        ->and($entry->status)->toBe('submitted')->and(Record::sole()->finance_entry_id)->toBe($entry->id);
});

test('documents of the Fahrzeugakte, internal ones only for the Fahrzeugwarte', function () {
    $schein = $this->f->addDocument($this->wart, $this->hlf, ['kind' => 'registration', 'valid_until' => '2030-01-31'], UploadedFile::fake()->create('schein.pdf', 120, 'application/pdf'));
    $kauf = $this->f->addDocument($this->wart, $this->hlf, ['kind' => 'purchase', 'title' => 'Kaufvertrag', 'internal' => true], UploadedFile::fake()->create('kauf.pdf', 80, 'application/pdf'));

    expect($schein->title)->toBe('Fahrzeugschein / Zulassung');
    expect(fn () => $this->f->addDocument($this->hanna, $this->hlf, ['kind' => 'other'], UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->hanna)->get(route('fahrzeuge.document', $schein))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->hanna)->get(route('fahrzeuge.document', $kauf))->assertForbidden();
    $this->actingAs($this->wart)->get(route('fahrzeuge.document', $kauf))->assertOk();

    Livewire::actingAs($this->hanna)->test(VehicleDetail::class, ['vehicle' => $this->hlf])->assertSee('Fahrzeugschein / Zulassung')->assertDontSee('Kaufvertrag');
    expect(Document::count())->toBe(2);
});

test('deadlines are in the Kalender of the Fahrzeugwarte and in the Fristenliste', function () {
    $event = Event::where('managed_by', 'fahrzeuge')->sole();
    expect($event->title)->toBe('HLF 20: UVV-Prüfung (DGUV Grundsatz 314-003)')->and($event->type)->toBe('wartung')
        ->and($event->invitees()->pluck('users.id')->all())->toBe([$this->wart->id])->and($this->uvv->refresh()->event_id)->toBe($event->id);

    // Done: the entry moves to the next date
    $this->f->recordWork($this->wart, $this->hlf, ['deadline_id' => $this->uvv->id, 'kind' => 'inspection', 'done_on' => FleetService::today()->toDateString()]);
    $moved = Event::where('managed_by', 'fahrzeuge')->sole();
    expect($moved->starts_at->setTimezone('Europe/Berlin')->toDateString())->toBe(FleetService::today()->addMonths(12)->toDateString());

    $overdue = $this->f->saveDeadline($this->wart, $this->hlf, null, ['title' => 'Hauptuntersuchung (HU)', 'interval_months' => 12, 'due_on' => FleetService::today()->subDays(3)->toDateString()]);
    Livewire::actingAs($this->hanna)->test(Deadlines::class)->set('filter', 'overdue')
        ->assertSee('Hauptuntersuchung (HU)')->assertDontSee('UVV-Prüfung')
        ->set('filter', 'all')->assertSee('UVV-Prüfung');

    $this->f->deleteDeadline($this->wart, $overdue);
    expect(Event::where('managed_by', 'fahrzeuge')->count())->toBe(1);
});

test('a vehicle QR label leads to its Fahrzeugakte, where a Mangel is reported for the vehicle', function () {
    $this->actingAs($this->wart)->get(route('fahrzeuge.label', $this->hlf))->assertOk()->assertSee('FZ-'.$this->hlf->id);
    $this->actingAs($this->hanna)->get(route('fahrzeuge.label', $this->hlf))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('scan.code', 'FZ-'.$this->hlf->id))->assertRedirect(route('fahrzeuge.show', $this->hlf->id));

    Livewire::actingAs($this->hanna)->withQueryParams(['fahrzeug' => $this->hlf->id])->test(ReportForm::class)
        ->assertSet('vehicleId', $this->hlf->id)
        ->set('title', 'Spiegel rechts lose')->call('save')->assertHasNoErrors();
    expect(Defect::sole()->vehicle_id)->toBe($this->hlf->id);

    Livewire::actingAs($this->hanna)->test(Fleet::class)->assertSee('1 offener Mangel');
    Livewire::actingAs($this->hanna)->test(VehicleDetail::class, ['vehicle' => $this->hlf])->assertSee('Spiegel rechts lose')->assertSee('Mangel melden');
});

test('Beschaffung data and the Jahresbericht section', function () {
    $this->f->saveVehicle($this->wart, $this->hlf, ['name' => 'HLF 20', 'color' => '#dc2626', 'purchased_on' => '2019-05-02', 'purchase_price' => '385.000,00',
        'supplier' => 'Aufbau Musterwerk', 'funding' => 'Landesförderung 40 %', 'warranty_until' => '2021-05-02']);
    $profile = Profile::where('vehicle_id', $this->hlf->id)->sole();
    expect($profile->purchase_price_cents)->toBe(38500000)->and($profile->funding)->toBe('Landesförderung 40 %');

    Livewire::actingAs($this->wart)->test(VehicleDetail::class, ['vehicle' => $this->hlf])->assertSee('385.000,00')->assertSee('(abgelaufen)');
    Livewire::actingAs($this->hanna)->test(VehicleDetail::class, ['vehicle' => $this->hlf])->assertDontSee('385.000,00')->assertSee('Aufbau Musterwerk');

    $this->f->recordWork($this->wart, $this->hlf, ['kind' => 'repair', 'title' => 'Bremsbeläge vorne', 'done_on' => FleetService::today()->toDateString(), 'cost_cents' => 48000]);
    $section = collect(app(AnnualReportRegistry::class)->for((int) now('Europe/Berlin')->year))->firstWhere('title', 'Fahrzeuge: Prüfungen, Wartungen & Reparaturen');
    expect($section['html'])->toContain('HLF 20')->toContain('480,00');
});
