<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\ModuleUser;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Lehrgaenge\src\Livewire\CourseList;
use Modules\Lehrgaenge\src\Livewire\MemberProfile;
use Modules\Lehrgaenge\src\Livewire\Members;
use Modules\Lehrgaenge\src\Livewire\MyCourses;
use Modules\Lehrgaenge\src\Livewire\Qualifications;
use Modules\Lehrgaenge\src\Livewire\Registrations;
use Modules\Lehrgaenge\src\Livewire\ResultImports;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Deletion;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Notifications\CatalogChangedNotification;
use Modules\Lehrgaenge\src\Notifications\CourseConfirmedNotification;
use Modules\Lehrgaenge\src\Notifications\CourseReminderNotification;
use Modules\Lehrgaenge\src\Notifications\RefreshDigestNotification;
use Modules\Lehrgaenge\src\Notifications\RefreshDueNotification;
use Modules\Lehrgaenge\src\Notifications\RegisteredByManagerNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationCancelledNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationRejectedNotification;
use Modules\Lehrgaenge\src\Notifications\RequestReceivedNotification;
use Modules\Lehrgaenge\src\Notifications\WaitlistedNotification;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use Modules\Lehrgaenge\src\Services\RegistrationService;
use Modules\Lehrgaenge\src\Services\ResultImport;

/**
 * A Restplätze page like fox112 delivers it (ISO-8859-1). Rows: [id, name, suffix, dated]; dated rows
 * get a number and dates, the others the wide cell "ohne festen Termin".
 */
function portalPage(array $rows): string
{
    $html = '<html><body><TABLE><TR><TD></TD><TD><B>Verfügbare Lehrgänge</B></TD><TD></TD><TD><B>Nr.</B></TD><TD></TD><TD><B>1.Tag</B></TD><TD></TD><TD><B>letzter Tag</B></TD><TD></TD><TD><B>Tage</B></TD><TD></TD><TD><B>Restpl.</B></TD><TD></TD><TD></TD></TR>';

    foreach ($rows as [$id, $name, $suffix, $dated]) {
        $html .= '<TR BGCOLOR="#EEEEEE"><TD></TD><TD><FONT SIZE="-1"><B>'.$name.'</B>'.($suffix ? ' '.$suffix : '').'</FONT></TD><TD></TD>';
        $html .= $dated
            ? '<TD ALIGN="RIGHT"><FONT SIZE="-1"><B>XY 27-01</B></FONT></TD><TD></TD><TD><FONT SIZE="-1">12.06.2027</FONT></TD><TD></TD><TD><FONT SIZE="-1">26.06.2027</FONT></TD><TD></TD><TD ALIGN="RIGHT"><FONT SIZE="-1">3</FONT></TD><TD></TD><TD ALIGN="RIGHT"><FONT SIZE="-1">4</FONT></TD>'
            : '<TD COLSPAN="9" ALIGN="CENTER"><FONT SIZE="-1">ohne festen Termin</FONT></TD>';
        $html .= '<TD></TD><TD><A HREF="?lehrgangx='.$id.'"><IMG SRC="x.gif"></A></TD></TR>';
    }

    return mb_convert_encoding($html.'</TABLE></body></html>', 'ISO-8859-1', 'UTF-8');
}

/** What the fake portal answers; Http::fake stacks, so it is registered once and reads this */
function fakePortal(array $rows, ?string $body = null, int $status = 200): void
{
    app()->instance('test.portal', compact('rows', 'body', 'status'));
}

beforeEach(function () {
    Module::where('key', 'lehrgaenge')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-01 10:00', 'Europe/Berlin'));
    Notification::fake();
    Http::fake(function () {
        $portal = app('test.portal');

        return Http::response($portal['body'] ?? portalPage($portal['rows']), $portal['status'], ['Content-Type' => 'text/html; charset=iso-8859-1']);
    });

    $this->rows = [
        [101, 'Atemschutzgeräteträger', '(Wochenendlehrgang)', false],
        [102, 'Atemschutzgeräteträger', '(Wochenlehrgang)', false],
        [103, 'Truppführung Teil 1', null, false],
        [104, 'Tierrettung - Großtiere', null, false],
        [201, 'Gefahrenabwehr bei Bauunfällen', null, true],
    ];
    CatalogImporter::saveUrl('musterkreis');
    fakePortal($this->rows);
    app(CatalogImporter::class)->import();
    // fox112 only suggests: the Ausbildungsleitung offers them
    Course::whereNotNull('suggested_at')->update(['is_standard' => true, 'suggested_at' => null]);
    $this->agt = Course::where('name', 'Atemschutzgeräteträger')->first();

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);
    $this->leader->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    $this->member->moduleAccess()->create(['module' => 'lehrgaenge']);
    $this->r = app(RegistrationService::class);
});

function confirmDays(Record $record, array $dates = ['2027-07-03', '2027-07-10']): void
{
    app(RegistrationService::class)->confirm($record, array_map(fn ($d) => ['date' => $d, 'starts_at' => '08:00', 'ends_at' => '16:30', 'location' => 'FTZ Musterkreis'], $dates));
}

// ─── catalog from fox112 ─────────────────────────────────────────────────────

test('only the Lehrgänge without fixed date come from fox112, with their variants', function () {
    expect(Course::offered()->orderBy('name')->pluck('name')->all())->toBe(['Atemschutzgeräteträger', 'Tierrettung - Großtiere', 'Truppführung Teil 1'])
        ->and($this->agt->variants())->toBe(['(Wochenendlehrgang)', '(Wochenlehrgang)'])
        ->and(Course::where('name', 'Gefahrenabwehr bei Bauunfällen')->exists())->toBeFalse()
        ->and(CatalogImporter::lastRun())->toMatchArray(['ok' => true]);

    // A fresh install has nothing but the portal's Lehrgänge
    expect(Course::count())->toBe(3);
});

test('fox112 only suggests: the Ausbildungsleiter offers or ignores, nothing is offered by itself', function () {
    fakePortal([...$this->rows, [105, 'Vegetationsbrandbekämpfung', null, false], [106, 'Kettensägenführer', null, false]]);
    $stats = app(CatalogImporter::class)->import();
    expect($stats)->toMatchArray(['courses' => 2, 'gone' => 0])
        ->and(Course::offered()->where('name', 'Vegetationsbrandbekämpfung')->exists())->toBeFalse();
    Notification::assertSentTo($this->leader, CatalogChangedNotification::class);

    $veg = Course::where('name', 'Vegetationsbrandbekämpfung')->sole();
    $saw = Course::where('name', 'Kettensägenführer')->sole();
    Livewire::actingAs($this->leader)->test(CourseList::class)->assertSee('Vorschläge aus fox112 (2)')
        ->call('accept', $veg->id)->assertSee('„Vegetationsbrandbekämpfung“ wird angeboten.')
        ->call('ignore', $saw->id)->assertSee('nicht mehr vorgeschlagen');
    expect($veg->fresh()->isOffered())->toBeTrue()->and($saw->fresh()->isOffered())->toBeFalse();

    // Ignored stays quiet on the next import
    app(CatalogImporter::class)->import();
    expect($saw->fresh()->suggested_at)->toBeNull();
});

test('a Lehrgang that leaves fox112 is kept and flagged; the Ausbildungsleiter decides', function () {
    $this->r->request($this->member, $this->agt, null);
    fakePortal([$this->rows[2]]);
    $stats = app(CatalogImporter::class)->import();

    expect($stats)->toMatchArray(['listed' => 1, 'courses' => 0, 'gone' => 2])
        ->and(Course::offered()->count())->toBe(3)   // still offered, nothing deleted
        ->and($this->agt->fresh()->gone_since)->not->toBeNull();
    Notification::assertSentTo($this->leader, CatalogChangedNotification::class);

    $tier = Course::where('name', 'Tierrettung - Großtiere')->sole();
    Livewire::actingAs($this->leader)->test(CourseList::class)->assertSee('Nicht mehr in fox112 (2)')->assertSee('1 mit Interesse')
        ->call('keep', $this->agt->id)->assertSee('bleibt angeboten')
        ->call('remove', $tier->id);
    expect($this->agt->fresh())->gone_since->toBeNull()->isOffered()->toBeTrue()
        ->and(Course::find($tier->id))->toBeNull();

    // Back in fox112: nothing to decide
    fakePortal($this->rows);
    app(CatalogImporter::class)->import();
    expect($this->agt->fresh()->listed)->toBeTrue();
});

test('Lehrgänge are deleted from the list; with entries they are only hidden and fox112 suggests them again', function () {
    $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2027-01-10');
    $own = Course::create(['name' => 'Vegetationsbrandbekämpfung', 'is_standard' => true]);

    Livewire::actingAs($this->leader)->test(CourseList::class)
        ->call('remove', $own->id)->assertSee('gelöscht.')
        ->call('remove', $this->agt->id)->assertSee('die Einträge der Kameraden bleiben');
    expect(Course::find($own->id))->toBeNull()
        ->and($this->agt->fresh())->removed_at->not->toBeNull()->isOffered()->toBeFalse()
        ->and(Record::where('course_id', $this->agt->id)->count())->toBe(1);
    Livewire::actingAs($this->member)->test(MyCourses::class)->assertDontSee('Atemschutzgeräteträger</option>', false);

    // The next import suggests it again; offering brings it back
    app(CatalogImporter::class)->import();
    expect($this->agt->fresh()->suggested_at)->not->toBeNull()->and($this->agt->fresh()->isOffered())->toBeFalse();
    Livewire::actingAs($this->leader)->test(CourseList::class)->assertSee('von dir gelöscht, fox112 führt ihn weiter')->call('accept', $this->agt->id);
    expect($this->agt->fresh())->isOffered()->toBeTrue()->removed_at->toBeNull();
});

test('the Ausbildungsleiter adds a Lehrgang missing in fox112; Kameraden can report Interesse for it', function () {
    Livewire::actingAs($this->leader)->test(CourseList::class)
        ->set('newName', '  Vegetationsbrandbekämpfung ')->call('add')
        ->assertSee('„Vegetationsbrandbekämpfung“ steht auf der Lehrgangsliste und wird angeboten.', false)
        ->set('newName', 'vegetationsbrandbekämpfung')->call('add')
        ->assertHasErrors('newName');

    $course = Course::where('name', 'Vegetationsbrandbekämpfung')->sole();
    expect($course->is_standard)->toBeTrue()->and($course->listed)->toBeFalse();

    Livewire::actingAs($this->member)->test(MyCourses::class)
        ->assertSee('Vegetationsbrandbekämpfung')
        ->call('startRequest', $course->id)->call('sendRequest')
        ->assertSee('Interesse gemeldet');

    // The daily import leaves it alone
    app(CatalogImporter::class)->import();
    expect($course->fresh()->isOffered())->toBeTrue();

    $this->actingAs($this->member)->get('/lehrgaenge/lehrgangsliste')->assertForbidden();
    $this->actingAs($this->leader)->get('/lehrgaenge/lehrgangsliste')->assertOk()->assertSee('angeboten');
});

test('the Ausbildungsleitung sets the fox112 page of the own Kreis; only fox112 addresses are taken', function () {
    expect(CatalogImporter::url())->toBe('https://musterkreis.fox112.de/HomePageLehrgaengeRestplaetze.php');
    Http::assertSent(fn ($request) => $request->url() === 'https://musterkreis.fox112.de/HomePageLehrgaengeRestplaetze.php');

    expect(CatalogImporter::saveUrl('https://anderer-kreis.fox112.de'))->toBe('https://anderer-kreis.fox112.de/HomePageLehrgaengeRestplaetze.php')
        ->and(CatalogImporter::saveUrl('anderer-kreis.fox112.de/HomePageLehrgaengeRestplaetze.php'))->toBe('https://anderer-kreis.fox112.de/HomePageLehrgaengeRestplaetze.php');
    foreach (['http://kreis.fox112.de', 'https://example.com/fox112.de', 'https://fox112.de.example.com', 'https://kreis.fox112.de:8443/x'] as $bad) {
        expect(fn () => CatalogImporter::saveUrl($bad))->toThrow(ValidationException::class, 'fox112-Adresse');
    }

    Livewire::actingAs($this->leader)->test(CourseList::class)
        ->assertSet('foxUrl', 'https://anderer-kreis.fox112.de/HomePageLehrgaengeRestplaetze.php')
        ->set('foxUrl', 'https://evil.example.com')->call('saveFoxUrl')->assertHasErrors('foxUrl')
        ->set('foxUrl', 'musterkreis')->call('saveFoxUrl')->assertHasNoErrors()->assertSee('fox112-Adresse gespeichert. 3 Lehrgänge in fox112');

    // Without an address the daily import does nothing and reports no error
    CatalogImporter::saveUrl('');
    $this->artisan('lehrgaenge:import-catalog')->assertSuccessful()->expectsOutput('No fox112 address set, skipped');
    expect(fn () => app(CatalogImporter::class)->import())->toThrow(RuntimeException::class, 'Noch keine fox112-Adresse');
    Livewire::actingAs($this->leader)->test(Registrations::class)->assertSee('Noch keine fox112-Adresse');
});

test('a page without such Lehrgänge or an error changes nothing and is reported', function () {
    fakePortal([], body: '<html><body>Wartungsarbeiten</body></html>');
    expect(fn () => app(CatalogImporter::class)->import())->toThrow(RuntimeException::class, 'keine Lehrgänge');
    expect(Course::offered()->count())->toBe(3)->and(CatalogImporter::lastRun()['ok'])->toBeFalse();

    fakePortal([], body: '', status: 500);
    $this->artisan('lehrgaenge:import-catalog')->assertFailed();
    expect(Course::offered()->count())->toBe(3);
});

test('the daily import skips an inactive module; the Ausbildungsleiter can run it by hand', function () {
    Module::where('key', 'lehrgaenge')->update(['is_active' => false]);
    $this->artisan('lehrgaenge:import-catalog')->assertSuccessful();
    Http::assertSentCount(1); // only the import in beforeEach

    Module::where('key', 'lehrgaenge')->update(['is_active' => true]);
    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->assertSee('Lehrgänge von fox112 aktualisieren')
        ->call('import')
        ->assertSee('Lehrgänge aktualisiert: 3 Lehrgänge in fox112');
    Http::assertSentCount(2);

    fakePortal([], body: '', status: 503);
    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->call('import')
        ->assertSee('Aktualisieren fehlgeschlagen')
        ->assertSee('– fehlgeschlagen');
});

// ─── the process ─────────────────────────────────────────────────────────────

test('1-4: Bedarf, Dashboard of the Ausbildungsleiter, days entered, Kamerad gets mail and sees them', function () {
    $this->actingAs($this->member)->get(route('lehrgaenge.index'))->assertOk()->assertSee('Lehrgänge des Kreises');

    Livewire::actingAs($this->member)->test(MyCourses::class)
        ->assertSee('Truppführung Teil 1')
        ->assertSee('(Wochenendlehrgang) · (Wochenlehrgang)')
        ->assertDontSee('Gefahrenabwehr')
        ->call('startRequest', $this->agt->id)
        ->set('requestNote', 'lieber am Wochenende')
        ->call('sendRequest')
        ->assertHasNoErrors()
        ->assertSee('Dein Interesse an „Atemschutzgeräteträger“ ist gemeldet', false)
        ->assertSee('Interesse gemeldet am 01.06.2027');

    $record = Record::sole();
    Notification::assertSentTo($this->leader, RequestReceivedNotification::class, fn ($n) => str_contains(implode(' ', $n->toMail($this->leader)->introLines), 'lieber am Wochenende'));

    $this->actingAs($this->leader)->get(route('lehrgaenge.registrations'))->assertOk()->assertSee('Lehrgangsanmeldungen');
    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->assertSee('Hanna Hydrant')
        ->assertSee('lieber am Wochenende')
        ->call('editSessions', $record->id)
        ->set('sessions.0.date', '2027-07-03')
        ->set('sessions.0.location', 'FTZ Musterkreis')
        ->call('addSession')
        ->assertSet('sessions.1.location', 'FTZ Musterkreis')
        ->set('sessions.1.date', '2027-07-10')
        ->call('saveSessions')
        ->assertHasNoErrors()
        ->assertSee('E-Mail ist raus');

    $record->refresh();
    expect($record->status)->toBe('confirmed')->and($record->starts_on->toDateString())->toBe('2027-07-03')->and($record->sessions)->toHaveCount(2);

    Notification::assertSentTo($this->member, CourseConfirmedNotification::class, function ($n) {
        $lines = implode(' ', $n->toMail($this->member)->introLines);

        return str_contains($lines, 'Sa., 03.07.2027 · 08:00–16:00 Uhr · FTZ Musterkreis') && str_contains($lines, 'Sa., 10.07.2027');
    });

    Livewire::actingAs($this->member)->test(MyCourses::class)->assertSee('Bestätigt')->assertSee('Sa., 03.07.2027 · 08:00–16:00 Uhr · FTZ Musterkreis');
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Dein nächster Lehrgang');
});

test('Bedarf is listed per Lehrgang in the order it came in', function () {
    $late = User::factory()->create(['name' => 'Anton Ascher']);
    $this->r->request($this->member, $this->agt, null);
    $this->travel(2)->days();
    $this->r->request($late, $this->agt, null);

    $html = Livewire::actingAs($this->leader)->test(Registrations::class)->html();
    expect(strpos($html, 'Hanna Hydrant'))->toBeLessThan(strpos($html, 'Anton Ascher'))->and($html)->toContain('2 Kameraden');
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('2 Kameraden mit Interesse an einem Lehrgang');
});

test('Bedarf is not announced twice or for an absolvierter Lehrgang', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    expect(fn () => $this->r->request($this->member, $this->agt, null))->toThrow(ValidationException::class, 'schon Interesse');

    confirmDays($record);
    $this->r->complete($record->refresh());
    expect(fn () => $this->r->request($this->member, $this->agt, null))->toThrow(ValidationException::class, 'schon absolviert');
});

test('days must have a date and valid times; a correction tells the Kamerad again', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    expect(fn () => $this->r->confirm($record, [['date' => '']]))->toThrow(ValidationException::class, 'mindestens einen');
    expect(fn () => $this->r->confirm($record, [['date' => '2027-07-03', 'starts_at' => '25:00']]))->toThrow(ValidationException::class);

    confirmDays($record);
    confirmDays($record->refresh(), ['2027-08-07']);

    expect($record->refresh()->sessions)->toHaveCount(1)->and($record->starts_on->toDateString())->toBe('2027-08-07');
    Notification::assertSentTo($this->member, CourseConfirmedNotification::class, fn ($n) => str_contains($n->toMail($this->member)->subject, 'geändert'));
});

test('5: the Kamerad is reminded 14 days before the first day, once', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    confirmDays($record, ['2027-07-03']);

    $this->travelTo(Carbon::parse('2027-06-18 07:20', 'Europe/Berlin'));
    expect($this->r->remindUpcoming())->toBe(0);

    $this->travelTo(Carbon::parse('2027-06-19 07:20', 'Europe/Berlin'));
    $this->artisan('lehrgaenge:remind-upcoming')->assertSuccessful();
    expect($this->r->remindUpcoming())->toBe(0);

    Notification::assertSentToTimes($this->member, CourseReminderNotification::class, 1);
    Notification::assertSentTo($this->member, CourseReminderNotification::class, fn ($n) => str_contains($n->toMail($this->member)->subject, 'beginnt bald'));
});

test('a Lehrgang confirmed within the 14 days gets no extra reminder', function () {
    confirmDays($this->r->request($this->member, $this->agt, null), ['2027-06-10']);

    expect($this->r->remindUpcoming())->toBe(0);
});

test('6+7: the Kamerad cancels with a reason; the Ausbildungsleiter is told and both see it', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    confirmDays($record);

    Livewire::actingAs($this->member)->test(MyCourses::class)
        ->call('startCancel', $record->id)
        ->call('cancel')
        ->assertHasErrors('cancelReason')
        ->set('cancelReason', 'Beruflich verhindert')
        ->call('cancel')
        ->assertSee('abgesagt')
        ->assertSee('Grund: Beruflich verhindert');

    expect($record->refresh())->status->toBe('cancelled')->cancel_reason->toBe('Beruflich verhindert');
    Notification::assertSentTo($this->leader, RegistrationCancelledNotification::class, fn ($n) => str_contains(implode(' ', $n->toMail($this->leader)->introLines), 'in fox112 wieder abmelden'));

    Livewire::actingAs($this->leader)->test(Registrations::class)->assertSee('bitte in fox112 wieder abmelden')->assertSee('Grund: Beruflich verhindert')->assertSee('(war bestätigt)');
    Livewire::actingAs($this->leader)->test(MemberProfile::class, ['member' => $this->member])->assertSee('Grund: Beruflich verhindert');
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Absage – in fox112 abmelden');

    // Bedarf can be announced again afterwards
    $this->r->request($this->member, $this->agt, null);
    expect(Record::count())->toBe(2);
});

test('Bedarf can be cancelled before it is confirmed, too', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    $this->r->cancel($record, 'Doch keine Zeit');

    expect($record->refresh()->status)->toBe('cancelled');
    expect(fn () => $this->r->cancel($record, 'nochmal'))->toThrow(ValidationException::class);
});

test('absolviert: the Ausbildungsleiter sets it; Ausbildungsleiter and Wehrführung see it in the profile', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    confirmDays($record);

    Livewire::actingAs($this->leader)->test(Registrations::class)->call('complete', $record->id)->assertSee('absolviert');
    expect($record->refresh())->status->toBe('completed')->and($record->completed_on->toDateString())->toBe('2027-07-10');

    Livewire::actingAs($this->member)->test(MyCourses::class)->assertSee('Absolvierte Lehrgänge')->assertSee('absolviert 10.07.2027');

    $chief = User::factory()->create(['name' => 'Wilma Wehrführer', 'phone' => null]);
    $chief->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'wehrführung']);
    $this->actingAs($chief)->get(route('lehrgaenge.members'))->assertOk();
    $this->actingAs($chief)->get(route('lehrgaenge.member', $this->member))->assertOk();
    $this->actingAs($chief)->get(route('lehrgaenge.registrations'))->assertForbidden();
    Livewire::actingAs($chief)->test(Members::class)->assertSee('Hanna Hydrant')->assertSee('1 absolviert');
    Livewire::actingAs($chief)->test(MemberProfile::class, ['member' => $this->member])->assertSee('Atemschutzgeräteträger')->assertSee('absolviert 10.07.2027');
});

test('only a confirmed Lehrgang can be set absolviert', function () {
    $record = $this->r->request($this->member, $this->agt, null);

    expect(fn () => $this->r->complete($record))->toThrow(ValidationException::class);
});

test('members see only their own page', function () {
    foreach (['lehrgaenge.registrations', 'lehrgaenge.members'] as $route) {
        $this->actingAs($this->member)->get(route($route))->assertForbidden();
    }
    $this->actingAs($this->member)->get(route('lehrgaenge.member', $this->leader))->assertForbidden();
    Livewire::actingAs($this->member)->test(Registrations::class)->assertForbidden();
    $this->actingAs(User::factory()->create())->get(route('lehrgaenge.index'))->assertForbidden();
});

test('the Ausbildungsleiter declines a registration with a comment; the Kamerad gets a mail and sees it', function () {
    $record = $this->r->request($this->member, $this->agt, null);

    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->call('startReject', $record->id)
        ->call('reject')
        ->assertHasErrors('rejectComment')
        ->set('rejectComment', 'Erst die Grundausbildung abschließen')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSee('abgelehnt, E-Mail ist raus')
        ->assertSee('Abgelehnt (letzte 90 Tage)')
        ->assertSee('Kommentar: Erst die Grundausbildung abschließen');

    expect($record->refresh())->status->toBe('rejected')->cancel_reason->toBe('Erst die Grundausbildung abschließen');
    Notification::assertSentTo($this->member, RegistrationRejectedNotification::class,
        fn ($n) => str_contains(implode(' ', $n->toMail($this->member)->introLines), 'Erst die Grundausbildung abschließen'));

    Livewire::actingAs($this->member)->test(MyCourses::class)
        ->assertSee('von der Ausbildungsleitung abgelehnt')
        ->assertSee('Kommentar: Erst die Grundausbildung abschließen');
    Livewire::actingAs($this->leader)->test(MemberProfile::class, ['member' => $this->member])->assertSee('von der Ausbildungsleitung abgelehnt');

    // A confirmed one can be declined too, and Bedarf can be announced again
    $again = $this->r->request($this->member, $this->agt, null);
    confirmDays($again);
    $this->r->reject($again->refresh(), 'Lehrgang vom Kreis abgesagt');
    expect($again->refresh()->status)->toBe('rejected');
    expect(fn () => $this->r->reject($again, 'nochmal'))->toThrow(ValidationException::class);
});

test('admins can decline, too', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(Registrations::class)
        ->call('startReject', $record->id)->set('rejectComment', 'Kein Platz mehr')->call('reject')->assertHasNoErrors();

    expect($record->refresh()->status)->toBe('rejected');
});

// ─── nachtragen, Lehrgangsplan, Personalakte ─────────────────────────────────

test('the Ausbildungsleiter enters Lehrgänge absolviert outside the app, also ones not in the list', function () {
    $profile = Livewire::actingAs($this->leader)->test(MemberProfile::class, ['member' => $this->member]);

    $profile->set('entry', ['course' => 'Truppmann Teil 1', 'completed_on' => '2019-04-06', 'note' => 'Lehrgang im Nachbarkreis'])->call('addCompleted')
        ->assertHasNoErrors()->assertSee('„Truppmann Teil 1“ als absolviert eingetragen.')->assertSee('06.04.2019')->assertSee('nachgetragen');
    $record = Record::where('user_id', $this->member->id)->sole();
    expect($record)->status->toBe('completed')->entered_manually->toBeTrue()->notes->toBe('Lehrgang im Nachbarkreis')
        ->and($record->course->isOffered())->toBeFalse();   // known now, but not offered to the Kameraden

    // Twice, in the future or without name: no
    $profile->set('entry', ['course' => 'truppmann teil 1', 'completed_on' => '2020-01-01', 'note' => ''])->call('addCompleted')->assertHasErrors('entry_course');
    $profile->set('entry', ['course' => 'Maschinist', 'completed_on' => '2027-06-02', 'note' => ''])->call('addCompleted')->assertHasErrors('entry_date');
    $profile->set('entry', ['course' => ' ', 'completed_on' => '2020-01-01', 'note' => ''])->call('addCompleted')->assertHasErrors('entry_course');

    // A running registration for the Lehrgang is completed instead of a second record
    $open = $this->r->request($this->member, $this->agt, null);
    $profile->set('entry', ['course' => 'Atemschutzgeräteträger', 'completed_on' => '2027-05-20', 'note' => ''])->call('addCompleted')->assertHasNoErrors();
    expect($open->fresh())->status->toBe('completed')->entered_manually->toBeFalse()
        ->and(Record::where('user_id', $this->member->id)->count())->toBe(2);

    // The import keeps the course while it has records
    app(CatalogImporter::class)->import();
    expect($record->course->fresh())->not->toBeNull();

    // The Wehrführung sees, but does not enter
    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'wehrführung']);
    Livewire::actingAs($chief)->test(MemberProfile::class, ['member' => $this->member])->assertDontSee('nachtragen')
        ->set('entry', ['course' => 'Maschinist', 'completed_on' => '2020-01-01', 'note' => ''])->call('addCompleted')->assertForbidden();
});

test('the Lehrgangsplan lists the confirmed days with time, place and the Kameraden on them', function () {
    $other = User::factory()->create(['name' => 'Fritz Feuer']);
    foreach ([$this->member, $other] as $u) {
        confirmDays($this->r->request($u, $this->agt, null));
    }
    $tf = $this->r->request($other, Course::where('name', 'Truppführung Teil 1')->sole(), null);
    $this->r->confirm($tf, [['date' => '2027-08-14', 'starts_at' => '09:00', 'ends_at' => '17:00', 'location' => 'FTZ Musterkreis']]);
    $this->r->request($this->member, Course::where('name', 'Truppführung Teil 1')->sole(), null);   // only Interesse: not on the plan

    $html = $this->actingAs($this->leader)->get(route('lehrgaenge.plan'))->assertOk()
        ->assertSeeInOrder(['Juli 2027', 'Atemschutzgeräteträger', 'Sa., 03.07.2027 · 08:00–16:30 Uhr · FTZ Musterkreis', 'Fritz Feuer', 'Hanna Hydrant', 'Sa., 10.07.2027', 'August 2027', 'Truppführung Teil 1'])
        ->getContent();
    expect(substr_count($html, 'Sa., 03.07.2027'))->toBe(1);   // both Kameraden on one row

    // Past days only with the year
    $this->travelTo(Carbon::parse('2027-07-20 10:00', 'Europe/Berlin'));
    $this->get(route('lehrgaenge.plan'))->assertDontSee('03.07.2027')->assertSee('14.08.2027');
    $this->get(route('lehrgaenge.plan', ['jahr' => 2027]))->assertSee('03.07.2027');

    $this->actingAs($this->member)->get(route('lehrgaenge.plan'))->assertForbidden();
});

test('the Personalakte and the Personalverwaltung show the absolvierte Lehrgänge', function () {
    config(['locations.enabled' => false]);
    $this->r->recordCompleted($this->member, 'Truppmann Teil 1', '2019-04-06');
    confirmDays($this->r->request($this->member, $this->agt, null));

    $this->actingAs($this->member)->get(route('lehrgaenge.personnel-file'))->assertOk()
        ->assertSee('Lehrgänge und Qualifikationen')->assertSee('Truppmann Teil 1')->assertSee('06.04.2019')
        ->assertSee('Angemeldet')->assertSee('Sa., 03.07.2027');
    $this->get(route('profile'))->assertSee(route('lehrgaenge.personnel-file'));

    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $pv = User::factory()->create();
    $pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->actingAs($pv)->get(route('personalverwaltung.member', $this->member))->assertOk()->assertSee('Truppmann Teil 1');
});

test('only the Ausbildungsleiter hear about Interesse and Absagen; admins only when there is none', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);

    $record = $this->r->request($this->member, $this->agt, null);
    Notification::assertSentTo([$this->leader, $chief], RequestReceivedNotification::class);
    Notification::assertNotSentTo($admin, RequestReceivedNotification::class);

    $this->r->cancel($record, 'keine Zeit');
    Notification::assertSentTo($chief, RegistrationCancelledNotification::class);
    Notification::assertNotSentTo($admin, RegistrationCancelledNotification::class);

    // Without any Ausbildungsleiter the admins step in, so nothing gets lost
    ModuleUser::where('module', 'lehrgaenge')->where('role', 'ausbildungsleiter')->delete();
    $this->r->request($this->member, Course::where('name', 'Truppführung Teil 1')->sole(), null);
    Notification::assertSentTo($admin, RequestReceivedNotification::class);
});

test('the start page shows Interesse and Absagen to the same people who are notified', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->r->cancel($this->r->request($this->member, $this->agt, null), 'keine Zeit');

    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Absage – in fox112 abmelden');
    // An admin without the role is not notified, so the card doesn't nag either
    Livewire::actingAs($admin)->test(Home::class)->assertDontSee('Absage – in fox112 abmelden');

    // Without an Ausbildungsleiter the admins are notified – and see it
    ModuleUser::where('module', 'lehrgaenge')->where('role', 'ausbildungsleiter')->delete();
    Livewire::actingAs($admin)->test(Home::class)->assertSee('1 Absage – in fox112 abmelden');
});

test('admins and Ausbildungsleiter correct and delete absolvierte Lehrgänge, with or without a documented reason', function () {
    $viaApp = $this->r->request($this->member, $this->agt, null);
    confirmDays($viaApp);
    $this->r->complete($viaApp->refresh());
    $entered = $this->r->recordCompleted($this->member, 'Truppmann Teil 1', '2019-04-06');
    $admin = User::factory()->create(['is_admin' => true]);

    // Correct a Lehrgang that came through the app: other Lehrgang, date and note
    $profile = Livewire::actingAs($admin)->test(MemberProfile::class, ['member' => $this->member])
        ->call('startEdit', $viaApp->id)->assertSet('edit.course', 'Atemschutzgeräteträger')
        ->set('edit', ['course' => 'Truppmann Teil 1', 'completed_on' => '2027-05-10', 'note' => ''])->call('saveEdit')->assertHasErrors('entry_course')
        ->set('edit', ['course' => 'Atemschutzgeräteträger (Wochenlehrgang)', 'completed_on' => '2027-05-30', 'note' => 'Nachweis liegt vor'])->call('saveEdit')
        ->assertHasNoErrors()->assertSee('„Atemschutzgeräteträger (Wochenlehrgang)“ geändert.');
    expect($viaApp->fresh())->completed_on->toDateString()->toBe('2027-05-30')->notes->toBe('Nachweis liegt vor')
        ->and($viaApp->fresh()->course->name)->toBe('Atemschutzgeräteträger (Wochenlehrgang)');
    $profile->call('startEdit', $entered->id)->set('edit.completed_on', '2027-06-02')->call('saveEdit')->assertHasErrors('entry_date');

    // Delete with a reason: documented with what, when, why, by whom
    $profile->call('startDelete', $viaApp->id)->set('deleteReason', 'versehentlich beim falschen Kameraden eingetragen')->call('confirmDelete')
        ->assertSee('die Begründung ist dokumentiert')->assertSee('Gelöschte Lehrgänge')->assertSee('versehentlich beim falschen Kameraden eingetragen');
    expect(Record::find($viaApp->id))->toBeNull()
        ->and(Deletion::sole())->course_name->toBe('Atemschutzgeräteträger (Wochenlehrgang)')->deleted_by->toBe($admin->id);

    // Delete without a reason: simply gone, nothing documented
    Livewire::actingAs($this->leader)->test(MemberProfile::class, ['member' => $this->member])
        ->call('startDelete', $entered->id)->call('confirmDelete')->assertSee('„Truppmann Teil 1“ gelöscht.');
    expect(Record::find($entered->id))->toBeNull()->and(Deletion::count())->toBe(1);

    // The Wehrführung may not
    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'wehrführung']);
    $other = $this->r->recordCompleted($this->member, 'Maschinist', '2020-01-01');
    Livewire::actingAs($chief)->test(MemberProfile::class, ['member' => $this->member])->assertDontSee('Bearbeiten')
        ->call('startDelete', $other->id)->assertForbidden();
});

test('a Lehrgang with a refresher interval: valid until, Auffrischung by a later date, reminders', function () {
    $this->agt->update(['repeat_months' => 24]);
    $record = $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2025-07-01');
    expect($record->fresh('course')->validUntil()->toDateString())->toBe('2027-07-01')->and($record->fresh('course')->refreshState())->toBe('soon');

    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Auffrischung „Atemschutzgeräteträger“ fällig bis 01.07.2027');
    $this->artisan('lehrgaenge:remind-refresh')->assertSuccessful();
    Notification::assertSentTo($this->member, RefreshDueNotification::class);
    Notification::assertSentTo($this->leader, RefreshDigestNotification::class);
    Notification::fake();
    $this->artisan('lehrgaenge:remind-refresh')->assertSuccessful();
    Notification::assertNothingSent();

    // The Auffrischung: a later date of the same Lehrgang
    $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2027-05-20');
    expect(Record::where('user_id', $this->member->id)->count())->toBe(1)
        ->and($record->fresh('course'))->completed_on->toDateString()->toBe('2027-05-20')->refreshState()->toBe('ok');

    // Set on the Lehrgangsliste
    Livewire::actingAs($this->leader)->test(CourseList::class)->call('setRepeat', $this->agt->id, '200')->assertHasErrors('repeat')
        ->call('setRepeat', $this->agt->id, '')->assertSee('ohne Auffrischung');
    expect($this->agt->fresh()->repeat_months)->toBeNull();
});

test('the Ausbildungsstand shows Kameraden × Lehrgänge, also as CSV', function () {
    $this->agt->update(['repeat_months' => 12]);
    $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2025-01-10');   // overdue
    $this->r->recordCompleted($this->leader, 'Truppmann Teil 1', '2020-03-01');

    Livewire::actingAs($this->leader)->test(Qualifications::class)->assertSee('Atemschutzgeräteträger')->assertSee('Truppmann Teil 1')->assertSee('✓ 01/2025')->assertSee('bg-red-50', false);
    $csv = $this->actingAs($this->leader)->get(route('lehrgaenge.qualifications.csv'))->assertOk()->getContent();
    expect($csv)->toContain('10.01.2025 (Auffrischung überfällig)')->toContain('01.03.2020');
    $this->actingAs($this->member)->get(route('lehrgaenge.qualifications'))->assertForbidden();
});

test('results of an external Träger come in as CSV, with a preview', function () {
    $this->agt->update(['repeat_months' => 24]);
    $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2025-05-01');
    $fritz = User::factory()->create(['name' => 'Fritz Feuer', 'email' => 'fritz@example.com']);
    $csv = "Nachname;Vorname;E-Mail;Lehrgang;Abschluss;Ergebnis\n"
        ."Hydrant;Hanna;;Atemschutzgeräteträger;20.05.2026;bestanden\n"
        .";;fritz@example.com;Maschinist;15.04.2026;bestanden\n"
        .";;fritz@example.com;Sprechfunker;16.04.2026;nicht bestanden\n"
        ."Unbekannt;Udo;;Maschinist;15.04.2026;bestanden\n"
        ."Hydrant;Hanna;;Truppführung Teil 1;;bestanden\n";

    $rows = collect(app(ResultImport::class)->preview($csv));
    expect($rows->pluck('action')->all())->toBe(['refresh', 'new', 'failed', 'no_user', 'no_date']);

    $this->travelBack();
    Livewire::actingAs($this->leader)->test(ResultImports::class)
        ->set('file', UploadedFile::fake()->createWithContent('kreis-2027.csv', $csv))->assertSee('Auffrischung')->assertSee('Kamerad nicht gefunden')->assertSee('2 übernehmen')
        ->call('apply')->assertSee('2 Lehrgänge übernommen');
    expect(Record::where('user_id', $fritz->id)->sole())->status->toBe('completed')->notes->toBe('Import: kreis-2027.csv')
        ->and(Record::where('user_id', $this->member->id)->where('course_id', $this->agt->id)->sole()->completed_on->toDateString())->toBe('2026-05-20');

    $this->actingAs($this->member)->get(route('lehrgaenge.import'))->assertForbidden();
});

// ─── Ausbildungsleiter registers Kameraden, Kameraden see their state ────────

test('the Ausbildungsleiter registers Kameraden for a Lehrgang; they are told and skipped when already in', function () {
    $tim = User::factory()->create(['name' => 'Tim Tanklösch']);
    $this->r->request($this->member, $this->agt, null);

    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->set('registerCourse', (string) $this->agt->id)->set('registerMembers', [(string) $this->member->id, (string) $tim->id])
        ->set('registerNote', 'Absprache Dienstbesprechung')->call('registerMembers')
        ->assertSee('Angemeldet für „Atemschutzgeräteträger“: Tim Tanklösch.')->assertSee('Schon angemeldet oder absolviert: Hanna Hydrant.');

    $record = Record::where('user_id', $tim->id)->sole();
    expect($record->status)->toBe('requested')->and($record->notes)->toBe('Angemeldet von Bernd Blaulicht: Absprache Dienstbesprechung');
    Notification::assertSentTo($tim, RegisteredByManagerNotification::class);

    // Kameraden without the role can't
    expect(fn () => $this->r->registerFor($this->member, $this->agt, [$tim->id]))->toThrow(ValidationException::class);

    // Tim (no access to the module) sees it on his start page, Hanna with access always sees her Lehrgänge
    Livewire::actingAs($tim)->test(Home::class)->assertSee('wartet auf die Anmeldung')->assertDontSee(route('lehrgaenge.index'));
    Livewire::actingAs($this->member)->test(Home::class)->assertSee('Lehrgänge')->assertSee('0 Lehrgänge absolviert')->assertSee(route('lehrgaenge.index'));
});

test('Interesse registered in fox112 moves to the Warteliste and leaves the start page count', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Kamerad mit Interesse an einem Lehrgang');

    Livewire::actingAs($this->leader)->test(Registrations::class)
        ->call('waitlist', $record->id)->assertSee('steht auf der Warteliste')->assertSee('Warteliste')->assertSee('Zurück zu Interesse');
    expect($record->fresh())->status->toBe('requested')->isWaitlisted()->toBeTrue()->statusLabel()->toBe('Warteliste');
    Notification::assertSentTo($this->member, WaitlistedNotification::class);

    Livewire::actingAs($this->leader)->test(Home::class)->assertDontSee('mit Interesse an einem Lehrgang');
    Livewire::actingAs($this->member)->test(MyCourses::class)->assertSee('du stehst auf der Warteliste');

    // Still open: the Kamerad can cancel, the Ausbildungsleiter confirms the days as before
    confirmDays($record->fresh());
    expect($record->fresh()->status)->toBe('confirmed');

    // Taken back by mistake
    $other = $this->r->request($this->member, Course::where('name', 'Truppführung Teil 1')->first(), null);
    $this->r->waitlist($other);
    Livewire::actingAs($this->leader)->test(Registrations::class)->call('waitlist', $other->id, false);
    expect($other->fresh()->waitlisted_at)->toBeNull();
});

test('an Absage marked as signed off in fox112 no longer shows red on the start page', function () {
    $record = $this->r->request($this->member, $this->agt, null);
    $this->r->cancel($record, 'keine Zeit');
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Absage – in fox112 abmelden');

    // Not dealt with: still red after the 7 days the start page used to show
    $this->travel(10)->days();
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Absage – in fox112 abmelden');

    Livewire::actingAs($this->leader)->test(Registrations::class)->call('markCancelSeen', $record->id)->assertSee('In fox112 abgemeldet');
    expect($record->fresh()->cancel_seen_at)->not->toBeNull();
    Livewire::actingAs($this->leader)->test(Home::class)->assertDontSee('Absage – in fox112 abmelden');

    Livewire::actingAs($this->leader)->test(Registrations::class)->call('markCancelSeen', $record->id, false);
    expect($record->fresh()->cancel_seen_at)->toBeNull();
});

test('absolvierte Lehrgänge go in without a date, one by one or for many Kameraden at once', function () {
    $single = $this->r->recordCompleted($this->member, 'Truppmann Teil 1', null);
    expect($single)->status->toBe('completed')->completed_on->toBeNull()->validUntil()->toBeNull();
    Livewire::actingAs($this->leader)->test(MemberProfile::class, ['member' => $this->member])->assertSee('(Datum unbekannt)');

    // A later Lehrgang with a date replaces nothing without a refresher; with a date for an undated one the refresher counts from it
    $this->agt->update(['repeat_months' => 12]);
    $undated = $this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '');
    expect($this->r->recordCompleted($this->member, 'Atemschutzgeräteträger', '2027-01-15')->id)->toBe($undated->id)
        ->and($undated->fresh()->completed_on->toDateString())->toBe('2027-01-15');

    $anna = User::factory()->create(['name' => 'Anna Atemschutz']);
    $karl = User::factory()->create(['name' => 'Karl Kübelspritze']);
    $inactive = User::factory()->create(['name' => 'Otto Oldtimer', 'is_active' => false]);

    $page = Livewire::actingAs($this->leader)->test(Members::class)
        ->set('bulkCourse', 'Truppmann Teil 1')->assertSee('(hat ihn schon)')->assertDontSee('Otto Oldtimer')
        ->call('selectAll');
    expect($page->get('bulkMembers'))->toContain((string) $anna->id, (string) $karl->id)->not->toContain((string) $this->member->id);
    $page->set('bulkMembers', [(string) $anna->id, (string) $karl->id, (string) $this->member->id])
        ->set('bulkNote', 'aus der alten Liste')->call('addMany')
        ->assertHasNoErrors()->assertSee('2 Kameraden nachgetragen')->assertSee('Hatten den Lehrgang schon: Hanna Hydrant');
    expect(Record::where('status', 'completed')->whereIn('user_id', [$anna->id, $karl->id])->whereNull('completed_on')->where('entered_manually', true)->count())->toBe(2);

    // With a date, and the checks
    $page->set('bulkCourse', 'Maschinist')->set('bulkDate', '2020-05-01')->set('bulkMembers', [(string) $anna->id])->call('addMany');
    expect(Record::where('user_id', $anna->id)->whereHas('course', fn ($q) => $q->where('name', 'Maschinist'))->sole()->completed_on->toDateString())->toBe('2020-05-01');
    $page->set('bulkDate', '2030-01-01')->set('bulkMembers', [(string) $karl->id])->call('addMany')->assertHasErrors('entry_date');
    $page->set('bulkDate', '')->set('bulkMembers', [])->call('addMany')->assertHasErrors('entry_members');

    // Only admins and Ausbildungsleiter
    $chief = User::factory()->create();
    $chief->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'wehrführung']);
    Livewire::actingAs($chief)->test(Members::class)->assertDontSee('für mehrere Kameraden nachtragen')->call('addMany')->assertForbidden();
});
