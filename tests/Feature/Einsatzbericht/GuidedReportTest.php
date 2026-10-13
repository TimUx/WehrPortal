<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Livewire\ReportEditor;
use Modules\Einsatzbericht\src\Livewire\ReportList;
use Modules\Einsatzbericht\src\Livewire\Statistics;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Einsatzbericht\src\Notifications\ReportReminderNotification;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

beforeEach(function () {
    Module::where('key', 'einsatzbericht')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-05-10 20:00', 'Europe/Berlin'));

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $this->chief->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'wehrführung']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hlf = Vehicle::factory()->create(['name' => 'HLF 20', 'is_active' => true]);
    $this->r = app(ReportService::class);
});

function crewOn(Report $report, User $user, string $function = 'einsatzkraft', bool $ba = false): void
{
    $vehicle = $report->vehicles()->first() ?? test()->r->addVehicle(test()->leader, $report, test()->hlf->id);
    test()->r->assign(test()->leader, $vehicle, $user, $function, $ba);
}

test('the report asks for what its Einsatzart needs, and the Jahresstatistik counts it', function () {
    ReportService::setRequired([...Report::DEFAULT_REQUIRED, 'details']);
    $report = $this->r->create($this->leader);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->assertDontSee('Angaben zur Einsatzart')
        ->set('form.category', 'brand_mittel')->assertSee('Brandobjekt')->assertSee('Vermutete Brandursache')->assertDontSee('Art der Hilfeleistung')
        ->set('form.keyword', 'F2 Wohnung')->set('form.address', 'Hauptstraße 1, 12345 Musterstadt')
        ->set('form.details.cause', 'Elektrizität')->set('form.details.smoke_detector', 'Vorhanden und ausgelöst')->set('form.details.nonsense', 'x')
        ->call('save')->assertHasNoErrors();
    expect($report->refresh()->details)->toBe(['cause' => 'Elektrizität', 'smoke_detector' => 'Vorhanden und ausgelöst']);

    // Brandobjekt is required: closing names it
    crewOn($report, $this->hanna);
    expect(fn () => $this->r->close($this->leader, $report->refresh()))->toThrow(ValidationException::class, 'Angaben zur Einsatzart (Brandobjekt)');

    // Another Einsatzart drops the answers that don't belong to it
    $this->r->update($this->leader, $report, ['category' => 'th', 'details' => ['kind' => 'Verkehrsunfall', 'trapped' => '2', 'cause' => 'Elektrizität']]);
    expect($report->refresh()->details)->toBe(['kind' => 'Verkehrsunfall', 'trapped' => 2]);

    $this->r->close($this->leader, $report);
    $this->r->release($this->chief, $report->refresh());
    Livewire::actingAs($this->chief)->test(Statistics::class)->assertSee('Art der Hilfeleistung')->assertSee('Verkehrsunfall');
    $this->actingAs($this->leader)->get(route('einsatzbericht.print', $report))->assertSee('Eingeklemmte / eingeschlossene Personen')->assertSee('Verkehrsunfall');
});

test('Einsatzleiter and Wehrführung sign; the checksum shows corrections after the release', function () {
    $report = $this->r->create($this->leader);
    $this->r->update($this->leader, $report, ['keyword' => 'TH Baum', 'category' => 'th', 'address' => 'Waldweg 3, 12345 Musterstadt']);
    crewOn($report, $this->hanna);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $report])
        ->set('signature', signaturePng())->call('close')->assertHasNoErrors();
    expect($report->refresh()->status)->toBe('closed')->and($report->closed_signature)->not->toBeNull();

    Livewire::actingAs($this->chief)->test(ReportEditor::class, ['report' => $report])
        ->set('signature', signaturePng())->call('release')->assertSee('Bericht freigegeben und signiert.')->assertSee('signiert');
    $report->refresh();
    expect($report->released_hash)->toHaveLength(64)->and(ReportService::sealIntact($report))->toBeTrue();
    $this->actingAs($this->chief)->get(route('einsatzbericht.print', $report))->assertSee('Inhalt seitdem unverändert')->assertSee('data:image/png;base64,', false);

    // A correction by the Wehrführung after the release is visible
    $this->r->update($this->chief, $report, ['measures' => 'Baum zersägt und beiseite geräumt']);
    expect(ReportService::sealIntact($report->refresh()))->toBeFalse();
    Livewire::actingAs($this->chief)->test(ReportEditor::class, ['report' => $report])->assertSee('nach der Freigabe geändert');

    // Reopening drops signatures and checksum
    $this->r->reopen($this->chief, $report);
    expect($report->refresh()->released_hash)->toBeNull()->and($report->closed_signature)->toBeNull();
});

test('Nebenberichte: own crew and number, counted once in the statistics', function () {
    $main = $this->r->create($this->leader);
    $this->r->update($this->leader, $main, ['keyword' => 'F3 Scheune', 'category' => 'brand_gross', 'address' => 'Hofweg 1, 12345 Musterstadt']);
    $this->r->start($this->leader, $main);
    crewOn($main, $this->hanna, 'gruppenfuehrer');

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $main])
        ->set('sideLabel', 'Löschzug Nord')->call('createSide')->assertRedirect();
    $side = Report::where('parent_id', $main->id)->sole();
    expect($side->number)->toBe($main->number.'-1')->and($side->keyword)->toBe('F3 Scheune')->and($side->status)->toBe('active')
        ->and($side->side_label)->toBe('Löschzug Nord');
    crewOn($side, $this->leader, 'einsatzkraft', true);

    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $main])->assertSee($side->number)->assertSee('Gesamtstärke mit Nebenberichten: 0/1/1/2');
    Livewire::actingAs($this->leader)->test(ReportEditor::class, ['report' => $side])->assertSee('Nebenbericht „Löschzug Nord“ zu')->assertDontSee('Nebenbericht anlegen');
    expect(fn () => $this->r->createSide($this->leader, $side))->toThrow(ValidationException::class);

    foreach ([$main, $side] as $report) {
        $this->r->update($this->leader, $report, ['ended_at' => Carbon::parse('2027-05-10 21:00', 'Europe/Berlin')]);
        $this->r->close($this->leader, $report->refresh());
        $this->r->release($this->chief, $report->refresh());
    }
    $stats = $this->r->statistics(2027);
    expect($stats['total'])->toBe(1)->and($stats['sides'])->toBe(1)->and($stats['breathing'])->toBe(1);
    Livewire::actingAs($this->leader)->test(ReportList::class)->assertSee('Nebenbericht: Löschzug Nord');
});

test('the Statusliste shows what is open per Einsatzart, and reports left lying are reminded', function () {
    $open = $this->r->create($this->leader, now()->subDays(5));
    $this->r->update($this->leader, $open, ['keyword' => 'B1 Container', 'category' => 'brand_klein']);
    $waiting = $this->r->create($this->leader, now()->subDays(12));
    $this->r->update($this->leader, $waiting, ['keyword' => 'TH Öl', 'category' => 'th', 'address' => 'Ringstraße 2, 12345 Musterstadt']);
    crewOn($waiting, $this->hanna);
    $this->r->close($this->leader, $waiting->refresh());
    Report::whereKey($waiting->id)->update(['closed_at' => now()->subDays(9)]);
    $this->r->create($this->leader);   // today: not yet due

    Livewire::actingAs($this->leader)->test(ReportList::class)
        ->assertSee('Zur Freigabe')->assertSee('Noch nicht freigegeben: ')->assertSee('Brand – Kleinbrand 1');

    expect($this->r->remind())->toBe(2)->and($this->r->remind())->toBe(0);
    Notification::assertSentTo($this->leader, ReportReminderNotification::class, fn ($n) => str_contains($n->toMail($this->leader)->subject, 'noch offen'));
    Notification::assertSentTo($this->chief, ReportReminderNotification::class, fn ($n) => str_contains($n->toMail($this->chief)->subject, 'Freigabe'));
});
