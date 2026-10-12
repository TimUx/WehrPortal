<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Einsatzbericht\src\Services\ReportService;
use Modules\Objektplaene\src\Livewire\Kiosk;
use Modules\Objektplaene\src\Livewire\Library;
use Modules\Objektplaene\src\Livewire\ObjectDetail;
use Modules\Objektplaene\src\Livewire\ObjectForm;
use Modules\Objektplaene\src\Models\PlanObject;
use Modules\Objektplaene\src\Models\Version;
use Modules\Objektplaene\src\Notifications\ReviewDueNotification;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/** The school of the tests; 0.001° latitude are about 111 m */
const OP_LAT = 52.5;
const OP_LNG = 9.7;

beforeEach(function () {
    Module::whereIn('key', ['objektplaene', 'einsatzbericht'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();
    $this->travelTo(Carbon::parse('2027-05-10 10:00', 'Europe/Berlin'));

    $this->planer = User::factory()->create(['name' => 'Paula Planverwalter']);
    $this->planer->moduleAccess()->create(['module' => 'objektplaene', 'role' => 'planverwalter']);
    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hanna->moduleAccess()->create(['module' => 'objektplaene']);
    $this->p = app(ObjectPlanService::class);

    $this->school = $this->p->saveObject($this->planer, null, ['name' => 'Grundschule Musterstadt', 'object_type' => 'schule', 'hazard_class' => 'erhoeht',
        'address' => 'Schulstraße 1, Musterstadt', 'latitude' => OP_LAT, 'longitude' => OP_LNG, 'radius_m' => 120, 'has_bma' => true, 'has_fsd' => true,
        'hazards' => 'Chemieraum im 1. OG', 'review_interval_months' => 24]);
    $this->farm = $this->p->saveObject($this->planer, null, ['name' => 'Hof Lindenweg', 'object_type' => 'landwirtschaft', 'hazard_class' => 'hoch',
        'latitude' => OP_LAT + 0.02, 'longitude' => OP_LNG, 'has_hazardous_materials' => true]);
});

function pdf(string $name = 'feuerwehrplan.pdf'): UploadedFile
{
    // Real PDF bytes: an upload through Livewire checks the content, not the name
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
}

test('the Planverwalter creates objects; Kameraden only read', function () {
    Livewire::actingAs($this->planer)->test(ObjectForm::class)
        ->set('form.name', '')->call('save')->assertHasErrors('name')
        ->set('form.name', 'Pflegeheim Am Park')->set('form.object_type', 'pflege')->set('form.hazard_class', 'erhoeht')
        ->set('form.latitude', '52,51')->set('form.longitude', '')->call('save')->assertHasErrors('latitude')
        ->set('form.longitude', '9.71')->set('form.radius_m', '200')->call('save')->assertHasNoErrors()->assertRedirect();

    expect(PlanObject::where('name', 'Pflegeheim Am Park')->sole())->latitude->toBe(52.51)->radius_m->toBe(200)
        ->next_review_on->toDateString()->toBe('2029-05-10')
        ->and($this->school->next_review_on->toDateString())->toBe('2029-05-10');

    $this->actingAs($this->hanna)->get(route('objektplaene.create'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('objektplaene.index'))->assertOk()->assertSee('Grundschule Musterstadt')->assertSee('Gefährdung erhöht');
    expect(fn () => $this->p->saveObject($this->hanna, $this->school, ['name' => 'x']))->toThrow(ValidationException::class, 'Planverwalter');
});

test('a new version replaces the plan; the history stays for the Planverwalter only', function () {
    // Livewire's test uploads don't survive a travelled clock
    $this->travelBack();
    $doc = $this->p->addDocument($this->planer, $this->school, 'feuerwehrplan', 'Feuerwehrplan Hauptgebäude', pdf(), 'Erstausgabe', '2025-03-01');
    $old = Version::where('document_id', $doc->id)->sole();

    Livewire::actingAs($this->planer)->test(ObjectDetail::class, ['object' => $this->school])
        ->call('startVersion', $doc->id)->call('saveDocument')->assertHasErrors('file')
        ->set('file', pdf('feuerwehrplan-2027.pdf'))->set('doc.note', 'Anbau Turnhalle')->call('saveDocument')
        ->assertHasNoErrors()->assertSee('Neue Version gespeichert')
        ->call('toggleHistory', $doc->id)->assertSee('Version 1')->assertSee('Anbau Turnhalle')->assertSee('(aktuell)');

    $current = $doc->fresh()->current;
    expect($current->version)->toBe(2)->and($current->name)->toBe('feuerwehrplan-2027.pdf')->and($current->uploaded_by)->toBe($this->planer->id);

    $this->actingAs($this->hanna)->get(route('objektplaene.file', $current))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->hanna)->get(route('objektplaene.file', $old))->assertForbidden();
    $this->actingAs($this->planer)->get(route('objektplaene.file', $old))->assertOk();
    $this->actingAs($this->hanna)->get(route('objektplaene.object', $this->school))->assertOk()
        ->assertSee('Feuerwehrplan Hauptgebäude')->assertSee('Version 2')->assertSee('Chemieraum im 1. OG')->assertDontSee('Neue Version');
});

test('photos of plans become WebP; other files are refused', function () {
    $doc = $this->p->addDocument($this->planer, $this->school, 'laufkarte', 'Laufkarte BMA', UploadedFile::fake()->image('laufkarte.jpg', 1200, 900));
    expect($doc->current)->mime->toBe('image/webp')->name->toBe('laufkarte.webp');

    expect(fn () => $this->p->addDocument($this->planer, $this->school, 'sonstiges', 'Tabelle', UploadedFile::fake()->create('liste.xlsx', 10, 'application/vnd.ms-excel')))
        ->toThrow(ValidationException::class, 'PDF-Datei oder ein Bild');
});

test('at an Einsatz the plans of the Einsatzstelle come up everywhere; a far object does not', function () {
    $this->p->addDocument($this->planer, $this->school, 'feuerwehrplan', 'Feuerwehrplan Hauptgebäude', pdf());
    $leader = User::factory()->create();
    $leader->moduleAccess()->create(['module' => 'einsatzbericht', 'role' => 'einsatzleiter']);
    $leader->moduleAccess()->create(['module' => 'objektplaene']);
    $reports = app(ReportService::class);
    $report = $reports->create($leader);
    $reports->update($leader, $report, ['keyword' => 'BMA', 'latitude' => OP_LAT + 0.0015, 'longitude' => OP_LNG]);

    expect($this->p->forReport($report->refresh())->pluck('object.id')->all())->toBe([$this->school->id])
        ->and($this->p->running())->toHaveCount(0);
    $reports->start($leader, $report);

    $this->actingAs($leader)->get(route('einsatzbericht.show', $report))->assertOk()->assertSee('Objektpläne zur Einsatzstelle')->assertSee('Feuerwehrplan Hauptgebäude')->assertDontSee('Hof Lindenweg');
    $this->actingAs($leader)->get(route('einsatzbericht.situation', $report))->assertOk()->assertSee('Grundschule Musterstadt')->assertSee('167 m');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertSee('Einsatz BMA')->assertSee('Feuerwehrplan Hauptgebäude');
    Livewire::actingAs($this->hanna)->test(Kiosk::class)->assertSee('Einsatz BMA')->assertSee('Brandmeldeanlage · Schlüsseldepot (FSD)')
        ->set('search', 'Linden')->assertSee('Hof Lindenweg');

    $this->school->update(['is_active' => false]);
    expect($this->p->running())->toHaveCount(0);
});

test('library: filters, "Mein Standort", offline list and the service worker', function () {
    $doc = $this->p->addDocument($this->planer, $this->school, 'feuerwehrplan', 'Feuerwehrplan', pdf());

    Livewire::actingAs($this->hanna)->test(Library::class)
        ->assertSee('Grundschule Musterstadt')->assertSee('Hof Lindenweg')
        ->set('hazard', 'hoch')->assertDontSee('Grundschule Musterstadt')->assertDispatched('water-markers')
        ->set('hazard', '')->set('search', 'Schulstr')->assertDontSee('Hof Lindenweg')
        ->set('search', '')->call('nearPoint', OP_LAT + 0.019, OP_LNG)->assertSee('In der Nähe deines Standorts')->assertSee('111 m');

    expect($this->p->offlineUrls())->toContain('/objektplaene', '/objektplaene/objekt/'.$this->school->id, '/objektplaene/datei/'.$doc->current->id);
    $this->actingAs($this->hanna)->get(route('objektplaene.worker'))->assertOk()
        ->assertHeader('Service-Worker-Allowed', '/objektplaene')->assertSee('objektplaene-files-v1', false);
    $this->actingAs(User::factory()->create())->get(route('objektplaene.index'))->assertForbidden();
});

test('reviews: due objects go to the Planverwalter once; "Unterlagen geprüft" starts the next interval', function () {
    $this->travelTo(Carbon::parse('2029-05-11 07:05', 'Europe/Berlin'));

    $this->artisan('objektplaene:remind')->assertSuccessful();
    Notification::assertSentTo($this->planer, ReviewDueNotification::class, fn ($n, $c, $u) => str_contains(implode(' ', $n->toMail($u)->introLines), 'Grundschule Musterstadt'));
    Notification::fake();
    $this->artisan('objektplaene:remind')->assertSuccessful();
    Notification::assertNothingSent();

    Livewire::actingAs($this->planer)->test(Home::class)->assertSee('1 Objekt: Überprüfung der Pläne fällig');
    Livewire::actingAs($this->hanna)->test(Home::class)->assertDontSee('Überprüfung der Pläne fällig');
    Livewire::actingAs($this->planer)->test(Library::class, ['due' => true])->assertSee('Grundschule Musterstadt')->assertDontSee('Hof Lindenweg');

    Livewire::actingAs($this->planer)->test(ObjectDetail::class, ['object' => $this->school])
        ->set('reviewNote', 'Begehung mit dem Hausmeister')->call('review')->assertSee('Als geprüft vermerkt')->assertSee('Begehung mit dem Hausmeister');
    expect($this->school->fresh())->next_review_on->toDateString()->toBe('2031-05-11')->due_mailed_at->toBeNull();
});
