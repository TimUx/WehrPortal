<?php

use App\Livewire\Dashboard\Home;
use App\Models\Module;
use App\Models\User;
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
use Modules\Lehrgaenge\src\Livewire\Registrations;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Notifications\CourseConfirmedNotification;
use Modules\Lehrgaenge\src\Notifications\CourseReminderNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationCancelledNotification;
use Modules\Lehrgaenge\src\Notifications\RegistrationRejectedNotification;
use Modules\Lehrgaenge\src\Notifications\RequestReceivedNotification;
use Modules\Lehrgaenge\src\Services\CatalogImporter;
use Modules\Lehrgaenge\src\Services\RegistrationService;

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
    $this->agt = Course::where('name', 'Atemschutzgeräteträger')->first();

    $this->leader = User::factory()->create(['name' => 'Bernd Blaulicht', 'phone' => null]);
    $this->leader->moduleAccess()->create(['module' => 'lehrgaenge', 'role' => 'ausbildungsleiter']);
    $this->member = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => null]);
    $this->member->moduleAccess()->create(['module' => 'lehrgaenge']);
    $this->r = app(RegistrationService::class);
});

function confirmDays(Record $record, array $dates = ['2027-07-03', '2027-07-10']): void
{
    app(RegistrationService::class)->confirm($record, array_map(fn ($d) => ['date' => $d, 'starts_at' => '08:00', 'ends_at' => '16:30', 'location' => 'FTZ Bad Oldesloe'], $dates));
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

test('a Lehrgang that leaves fox112 stays offered from the Standardliste; taken off it, it ends with fox112', function () {
    $this->r->request($this->member, $this->agt, null);

    fakePortal([$this->rows[2]]);
    $stats = app(CatalogImporter::class)->import();

    expect($stats)->toMatchArray(['listed' => 1, 'courses' => 0, 'gone' => 2])
        ->and(Course::offered()->orderBy('name')->pluck('name')->all())->toBe(['Atemschutzgeräteträger', 'Tierrettung - Großtiere', 'Truppführung Teil 1'])
        ->and($this->agt->fresh()->listed)->toBeFalse();

    // Taken off the Standardliste: no longer offered; one nobody asked for goes with the next import
    $tier = Course::where('name', 'Tierrettung - Großtiere')->sole();
    Livewire::actingAs($this->leader)->test(CourseList::class)
        ->call('toggle', $this->agt->id)->assertSee('wird nicht mehr angeboten')
        ->call('toggle', $tier->id);
    expect(fn () => $this->r->request(User::factory()->create(), $this->agt->fresh(), null))->toThrow(ValidationException::class, 'nicht mehr angeboten');
    app(CatalogImporter::class)->import();
    expect(Course::where('name', 'Tierrettung - Großtiere')->exists())->toBeFalse()
        ->and($this->agt->fresh())->not->toBeNull();                                 // has a record, stays

    // Back on the page, back in the offer
    fakePortal($this->rows);
    app(CatalogImporter::class)->import();
    expect($this->agt->fresh()->isOffered())->toBeTrue();
});

test('the Ausbildungsleiter adds a Lehrgang missing in fox112; Kameraden can report Interesse for it', function () {
    Livewire::actingAs($this->leader)->test(CourseList::class)
        ->set('newName', '  Vegetationsbrandbekämpfung ')->call('add')
        ->assertSee('„Vegetationsbrandbekämpfung“ steht auf der Standardliste', false)
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
    $this->actingAs($this->leader)->get('/lehrgaenge/lehrgangsliste')->assertOk()->assertSee('Standardliste');
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
        ->set('sessions.0.location', 'FTZ Bad Oldesloe')
        ->call('addSession')
        ->assertSet('sessions.1.location', 'FTZ Bad Oldesloe')
        ->set('sessions.1.date', '2027-07-10')
        ->call('saveSessions')
        ->assertHasNoErrors()
        ->assertSee('E-Mail ist raus');

    $record->refresh();
    expect($record->status)->toBe('confirmed')->and($record->starts_on->toDateString())->toBe('2027-07-03')->and($record->sessions)->toHaveCount(2);

    Notification::assertSentTo($this->member, CourseConfirmedNotification::class, function ($n) {
        $lines = implode(' ', $n->toMail($this->member)->introLines);

        return str_contains($lines, 'Sa., 03.07.2027 · 08:00–16:00 Uhr · FTZ Bad Oldesloe') && str_contains($lines, 'Sa., 10.07.2027');
    });

    Livewire::actingAs($this->member)->test(MyCourses::class)->assertSee('Bestätigt')->assertSee('Sa., 03.07.2027 · 08:00–16:00 Uhr · FTZ Bad Oldesloe');
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
    Livewire::actingAs($this->leader)->test(Home::class)->assertSee('1 Absage in den letzten 7 Tagen');

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
