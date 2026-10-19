<?php

use App\Livewire\Dashboard\Home;
use App\Models\ActivityLog;
use App\Models\Module;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Verbandbuch\src\Livewire\EntryDetail;
use Modules\Verbandbuch\src\Livewire\EntryForm;
use Modules\Verbandbuch\src\Livewire\EntryList;
use Modules\Verbandbuch\src\Models\Entry;
use Modules\Verbandbuch\src\Models\Signature;
use Modules\Verbandbuch\src\Notifications\EntryAboutYouNotification;
use Modules\Verbandbuch\src\Notifications\EntryRecordedNotification;
use Modules\Verbandbuch\src\Services\VerbandbuchService;

beforeEach(function () {
    Module::where('key', 'verbandbuch')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    Notification::fake();

    $this->safety = User::factory()->create(['name' => 'Sigrid Sicher']);
    $this->safety->moduleAccess()->create(['module' => 'verbandbuch', 'role' => 'sicherheitsbeauftragter']);
    $this->chief = User::factory()->create(['name' => 'Wilma Wehrführer']);
    $this->chief->moduleAccess()->create(['module' => 'verbandbuch', 'role' => 'wehrführung']);
    $this->helper = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->helper->moduleAccess()->create(['module' => 'verbandbuch']);
    $this->hurt = User::factory()->create(['name' => 'Hanna Hydrant']);
    $this->hurt->moduleAccess()->create(['module' => 'verbandbuch']);
    $this->other = User::factory()->create(['name' => 'Otto Ortsbrand']);
    $this->other->moduleAccess()->create(['module' => 'verbandbuch']);

    $this->vb = app(VerbandbuchService::class);
});

function injuryEntry(User $by, array $overrides = [], ?string $injuredSignature = null): Entry
{
    return app(VerbandbuchService::class)->create($by, [
        'kind' => 'injury', 'occasion' => 'uebung', 'occurred_at' => now('Europe/Berlin')->subHour()->format('Y-m-d\TH:i'),
        'location' => 'Gerätehaus Fahrzeughalle', 'injured_user_id' => test()->hurt->id,
        'description' => 'Beim Aufrollen des B-Schlauchs die Hand an der Kupplung eingeklemmt.',
        'injury' => 'Quetschung linker Zeigefinger', 'witnesses' => 'Otto Ortsbrand',
        'first_aid_measures' => 'Gekühlt, Pflasterverband', 'first_aider_user_id' => $by->id,
        ...$overrides,
    ], signaturePng(), $injuredSignature);
}

test('a Kamerad writes and signs an entry; managers and the injured Kamerad are told without health data', function () {
    $entry = injuryEntry($this->helper, injuredSignature: signaturePng());

    expect($entry)->year->toBe(now('Europe/Berlin')->year)->number->toBe(1)
        ->injured_name->toBe('Hanna Hydrant')->first_aider_name->toBe('Bernd Blaulicht')->recorded_by_name->toBe('Bernd Blaulicht')
        ->retain_until->toDateString()->toBe(now('Europe/Berlin')->addYears(10)->endOfYear()->toDateString())
        ->and($entry->signatures()->pluck('role')->sort()->values()->all())->toBe(['injured', 'recorder'])
        ->and($this->vb->verify($entry))->toBeTrue();

    // Health data is encrypted in the database
    $raw = DB::table('vb_entries')->where('id', $entry->id)->first();
    expect($raw->description)->not->toContain('Schlauch')->and($raw->injury)->not->toContain('Quetschung')
        ->and($entry->fresh()->injury)->toBe('Quetschung linker Zeigefinger');

    Notification::assertSentTo([$this->safety, $this->chief], EntryRecordedNotification::class, function ($n, $channels, $notifiable) {
        $mail = $n->toMail($notifiable);
        $text = $mail->subject.' '.implode(' ', $mail->introLines);

        return ! str_contains($text, 'Quetschung') && ! str_contains($text, 'Hanna') && $mail->actionUrl === route('verbandbuch.show', 1);
    });
    Notification::assertNotSentTo($this->helper, EntryRecordedNotification::class);
    Notification::assertSentTo($this->hurt, EntryAboutYouNotification::class);

    // Not in the activity log
    expect(ActivityLog::where('subject_type', 'like', 'Modules\\\\Verbandbuch%')->count())->toBe(0);
});

test('entries are numbered per year', function () {
    injuryEntry($this->helper);
    $second = injuryEntry($this->helper);

    expect($second->code())->toBe(now('Europe/Berlin')->year.'-002');
});

test('the form needs what DGUV 204-021 asks for and a real signature', function () {
    expect(fn () => injuryEntry($this->helper, ['injured_user_id' => null, 'injury' => '', 'first_aid_measures' => '']))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKeys(['injured_name', 'injury', 'first_aid_measures']));

    expect(fn () => injuryEntry($this->helper, ['occurred_at' => now('Europe/Berlin')->addDay()->format('Y-m-d\TH:i')]))
        ->toThrow(ValidationException::class, 'Zukunft');

    expect(fn () => $this->vb->create($this->helper, ['kind' => 'incident', 'occasion' => 'dienst', 'occurred_at' => now('Europe/Berlin')->format('Y-m-d\TH:i'),
        'location' => 'Hof', 'description' => 'Leiter weggerutscht, niemand verletzt.'], signaturePng(blank: true)))
        ->toThrow(ValidationException::class, 'zu kurz');

    expect(fn () => $this->vb->create($this->helper, ['kind' => 'incident', 'occasion' => 'dienst', 'occurred_at' => now('Europe/Berlin')->format('Y-m-d\TH:i'),
        'location' => 'Hof', 'description' => 'Leiter weggerutscht.'], 'data:image/svg+xml;base64,PHN2Zz4='))
        ->toThrow(ValidationException::class, 'Bitte unterschreiben');

    // A Vorfall without injury needs no person and no first aid
    $incident = $this->vb->create($this->helper, ['kind' => 'incident', 'occasion' => 'dienst', 'occurred_at' => now('Europe/Berlin')->format('Y-m-d\TH:i'),
        'location' => 'Hof', 'description' => 'Leiter weggerutscht, niemand verletzt.', 'injury' => 'wird ignoriert', 'doctor_visited' => true], signaturePng());
    expect($incident)->injured_name->toBeNull()->injury->toBeNull()->doctor_visited->toBeFalse();

    // A guest who is not a Kamerad
    $guest = injuryEntry($this->helper, ['injured_user_id' => null, 'injured_name' => 'Gerda Gast', 'first_aider_user_id' => null, 'first_aider_name' => 'Rettungsdienst']);
    expect($guest)->injured_name->toBe('Gerda Gast')->injured_user_id->toBeNull()->first_aider_name->toBe('Rettungsdienst');
});

test('the Livewire form writes and signs the entry', function () {
    Livewire::actingAs($this->helper)->test(EntryForm::class)
        ->set('kind', 'accident')->set('occasion', 'einsatz')->set('location', 'Einsatzstelle Hauptstraße 12')
        ->set('injuredUserId', (string) $this->hurt->id)->set('description', 'Auf nasser Treppe ausgerutscht.')
        ->set('injury', 'Verstauchung rechtes Sprunggelenk')->set('firstAidMeasures', 'Hochgelagert, gekühlt')
        ->set('doctorVisited', true)
        ->call('save')
        ->assertHasErrors('signature')
        ->set('signature', signaturePng())
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('verbandbuch.show', Entry::sole()));

    expect(Entry::sole())->kind->toBe('accident')->doctor_visited->toBeTrue()->first_aider_name->toBe('Bernd Blaulicht')->needsReport()->toBeTrue();
    Notification::assertSentTo($this->safety, EntryRecordedNotification::class,
        fn ($n, $c, $notifiable) => str_contains(implode(' ', $n->toMail($notifiable)->introLines), 'Unfallanzeige'));
});

test('only the managers and the people in an entry can see it', function () {
    $entry = injuryEntry($this->helper);

    foreach ([$this->safety, $this->chief, $this->helper, $this->hurt] as $user) {
        $this->actingAs($user)->get(route('verbandbuch.show', $entry))->assertOk()->assertSee('Quetschung linker Zeigefinger');
    }
    $this->actingAs($this->other)->get(route('verbandbuch.show', $entry))->assertForbidden();
    $this->actingAs($this->other)->get(route('verbandbuch.print', $entry))->assertForbidden();
    $this->actingAs($this->other)->get(route('verbandbuch.print-year', $entry->year))->assertForbidden();

    Livewire::actingAs($this->other)->test(EntryList::class)->assertDontSee('Hanna Hydrant');
    Livewire::actingAs($this->hurt)->test(EntryList::class)->assertSee('Hanna Hydrant');
    Livewire::actingAs($this->safety)->test(EntryList::class)->assertSee('Hanna Hydrant')->set('search', 'Ortsbrand')->assertDontSee('Hanna Hydrant');
    Livewire::actingAs($this->hurt)->test(EntryList::class, ['personal' => true])->assertSee('Hanna Hydrant');
    Livewire::actingAs($this->helper)->test(EntryList::class, ['personal' => true])->assertDontSee('Hanna Hydrant');

    // Every look is logged
    $this->actingAs($this->safety)->get(route('verbandbuch.print', $entry))->assertOk();
    expect($entry->accessLogs()->pluck('action')->all())->toContain('created', 'viewed', 'printed');
});

test('a signed entry cannot be changed or deleted, not even in the database; Nachträge are chained', function () {
    $entry = injuryEntry($this->helper);

    // Each in a savepoint: a failed statement would otherwise abort the test's transaction
    expect(fn () => DB::transaction(fn () => DB::table('vb_entries')->where('id', $entry->id)->update(['location' => 'Woanders'])))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('vb_entries')->where('id', $entry->id)->delete()))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => Signature::where('entry_id', $entry->id)->update(['name' => 'Jemand'])))->toThrow(QueryException::class);

    $this->vb->amend($this->hurt, $entry, 'Am Folgetag beim Arzt, Prellung bestätigt.');
    $this->vb->amend($this->safety, $entry, 'Unfallkasse informiert.');
    expect($entry->amendments()->count())->toBe(2)->and($this->vb->verify($entry))->toBeTrue()
        ->and(fn () => $this->vb->amend($this->other, $entry, 'Ich auch'))->toThrow(ValidationException::class);

    // Tampering past the trigger is noticed
    DB::statement('ALTER TABLE vb_amendments DISABLE TRIGGER vb_amendments_append_only');
    DB::table('vb_amendments')->where('entry_id', $entry->id)->orderBy('id')->limit(1)->update(['user_name' => 'Jemand anderes']);
    DB::statement('ALTER TABLE vb_amendments ENABLE TRIGGER vb_amendments_append_only');
    expect($this->vb->verify($entry))->toBeFalse();

    Livewire::actingAs($this->safety)->test(EntryDetail::class, ['entry' => $entry])->assertSee('stimmt nicht mehr');
});

test('managers note the Unfallanzeige, cancel wrong entries and keep entries longer', function () {
    $entry = injuryEntry($this->helper, ['kind' => 'accident', 'doctor_visited' => true]);
    expect(Entry::awaitingReport()->count())->toBe(1);
    Livewire::actingAs($this->safety)->test(Home::class)->assertSee('Unfall mit Arztbesuch ohne vermerkte Unfallanzeige');
    Livewire::actingAs($this->helper)->test(Home::class)->assertDontSee('ohne vermerkte Unfallanzeige');

    expect(fn () => $this->vb->markReported($this->helper, $entry, now('Europe/Berlin')->toDateString(), null))->toThrow(ValidationException::class);

    Livewire::actingAs($this->safety)->test(EntryDetail::class, ['entry' => $entry])
        ->set('reportNote', 'Online-Meldung')->call('markReported')->assertHasNoErrors()
        ->call('toggleHold')
        ->call('void')->assertHasErrors('void_reason')
        ->set('voidReason', 'Doppelt erfasst')->call('void')->assertHasNoErrors()
        ->assertSee('Storniert');

    $entry->refresh();
    expect($entry)->report_note->toBe('Online-Meldung')->reported_by->toBe($this->safety->id)->hold->toBeTrue()
        ->void_reason->toBe('Doppelt erfasst')->isVoided()->toBeTrue()
        ->and(Entry::awaitingReport()->count())->toBe(0)
        ->and($this->vb->verify($entry))->toBeTrue()
        ->and(fn () => $this->vb->amend($this->hurt, $entry, 'Nachtrag nach Storno'))->toThrow(ValidationException::class);
});

test('entries are deleted after the retention time, unless kept', function () {
    $old = injuryEntry($this->helper);
    $kept = injuryEntry($this->helper);
    $current = injuryEntry($this->helper);
    $this->vb->setHold($this->safety, $kept, true);

    DB::statement('ALTER TABLE vb_entries DISABLE TRIGGER vb_entries_protect');
    DB::table('vb_entries')->whereIn('id', [$old->id, $kept->id])->update(['retain_until' => '2020-12-31']);
    DB::statement('ALTER TABLE vb_entries ENABLE TRIGGER vb_entries_protect');

    $this->artisan('model:prune', ['--model' => [Entry::class]])->assertSuccessful();

    expect(Entry::pluck('id')->all())->toEqualCanonicalizing([$kept->id, $current->id])
        ->and(Signature::where('entry_id', $old->id)->exists())->toBeFalse();
});

test('the pages open, and not while the module is off', function () {
    $entry = injuryEntry($this->helper);

    $this->actingAs($this->safety)->get(route('verbandbuch.index'))->assertOk()->assertSee('Verbandbuch');
    $this->actingAs($this->helper)->get(route('verbandbuch.index'))->assertOk()->assertSee('Meine Einträge');
    $this->actingAs($this->helper)->get(route('verbandbuch.create'))->assertOk()->assertSee('Unterschreiben und speichern');
    $this->actingAs($this->safety)->get(route('verbandbuch.print-year', $entry->year))->assertOk()->assertSee('Quetschung')->assertSee('unverändert');
    $this->actingAs($this->hurt)->get(route('verbandbuch.personnel-file'))->assertOk()->assertSee('Nr. '.$entry->code());

    Module::where('key', 'verbandbuch')->update(['is_active' => false]);
    Cache::forget('modules_active_keys');
    $this->actingAs($this->safety)->get(route('verbandbuch.index'))->assertNotFound();
});

test('the retention can be extended but never shortened, also not in the database', function () {
    $entry = injuryEntry($this->helper);
    $until = $entry->fresh()->retain_until;

    DB::table('vb_entries')->where('id', $entry->id)->update(['retain_until' => $until->copy()->addYear()->toDateString()]);
    expect($entry->fresh()->retain_until->toDateString())->toBe($until->copy()->addYear()->toDateString())
        ->and(fn () => DB::table('vb_entries')->where('id', $entry->id)->update(['retain_until' => '2020-12-31']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('vb_entries')->where('id', $entry->id)->update(['location' => 'anders']))->toThrow(QueryException::class);
});

test('someone without a profile in the app, e.g. a child of the Jugendfeuerwehr, is entered by name', function () {
    $this->actingAs($this->helper)->get(route('verbandbuch.create'))->assertOk()->assertSee('Person ohne Profil – Namen eintragen');
});
