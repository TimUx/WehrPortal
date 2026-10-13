<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\Settings;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\HistoryFile;
use Modules\Personalverwaltung\src\Services\PersonnelService;
use Modules\Personalverwaltung\src\Support\ServiceTime;

beforeEach(function () {
    Module::where('key', 'personalverwaltung')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2027-06-01 12:00', 'Europe/Berlin'));
    $this->pv = User::factory()->create();
    $this->pv->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->p = app(PersonnelService::class);
});

/** A Kamerad with a hand-made Diensthistorie */
function historyMember(array $entries, array $attributes = []): User
{
    $user = User::factory()->create(['joined_at' => '2000-03-01', ...$attributes]);
    HistoryEntry::where('user_id', $user->id)->delete();   // without the synced rows
    foreach ($entries as [$kind, $label, $since, $until, $ref]) {
        HistoryEntry::create(['user_id' => $user->id, 'kind' => $kind, 'label' => $label, 'since' => $since, 'until' => $until, 'ref_id' => $ref ?? null]);
    }

    return $user;
}

test('an uninterrupted career counts from the Eintritt, anniversaries on the day', function () {
    $u = historyMember([['status', 'Aktiv', '2000-03-01', null, null]]);
    expect(ServiceTime::years($u))->toBe(27)
        ->and(ServiceTime::reachedOn($u, 25)->toDateString())->toBe('2025-03-01')
        ->and(ServiceTime::years($u, Carbon::parse('2025-02-28')))->toBe(24)
        ->and(ServiceTime::years($u, Carbon::parse('2025-03-01')))->toBe(25);
});

test('an interruption is not counted and moves the Ehrung', function () {
    $u = historyMember([
        ['status', 'Aktiv', '2000-03-01', '2009-12-31', null],
        ['status', 'Ausgetreten', '2010-01-01', '2011-12-31', null],
        ['status', 'Aktiv', '2012-01-01', null, null],
    ]);
    $st = ServiceTime::on($u);
    expect($st['gaps'])->toBe(730)->and($st['years'])->toBe(25)
        ->and(ServiceTime::reachedOn($u, 25)->toDateString())->toBe('2027-03-01');

    $type = $this->p->saveHonorType($this->pv, null, 'Ehrenzeichen 25 Jahre', 25);
    $due = $this->p->dueHonors($this->pv, 2027, $u);
    expect($due)->toHaveCount(1)->and($due[0]['reached_on']->toDateString())->toBe('2027-03-01')
        ->and($this->p->dueHonors($this->pv, 2026, $u))->toHaveCount(0);
});

test('Dienstzeit in another Wehr counts; Jugendfeuerwehr only from the chosen age', function () {
    $other = historyMember([
        ['service', 'FF Nachbarstadt', '1995-03-01', '2000-02-29', null],
        ['status', 'Aktiv', '2000-03-01', null, null],
    ]);
    expect(ServiceTime::on($other))->years->toBe(32)->start->toDateString()->toBe('1995-03-01')
        ->and(ServiceTime::on($other)['other'])->toBeGreaterThan(1800);

    $jf = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    Livewire::actingAs($this->pv)->test(Settings::class)->set('youthGroupId', (string) $jf->id)->set('youthRule', 'age')->set('youthAge', 16)->call('saveRules')->assertHasNoErrors();

    $kid = historyMember([
        ['status', 'Aktiv', '1993-06-01', null, null],
        ['group', 'Jugendfeuerwehr', '1993-06-01', '2001-05-31', $jf->id],
    ], ['birth_date' => '1983-06-01']);
    $st = ServiceTime::on($kid);
    expect($st['start']->toDateString())->toBe('1999-06-01')->and($st['years'])->toBe(28)->and($st['youth'])->toBeGreaterThan(2000);

    // Without birth date the JF time counts fully, with a hint
    $unknown = historyMember([['status', 'Aktiv', '1993-06-01', null, null], ['group', 'Jugendfeuerwehr', '1993-06-01', '2001-05-31', $jf->id]]);
    expect(ServiceTime::on($unknown))->years->toBe(34)->notes->toHaveCount(1);

    // "zählt nicht"
    Livewire::actingAs($this->pv)->test(Settings::class)->set('youthRule', 'none')->call('saveRules');
    expect(ServiceTime::on($kid)['start']->toDateString())->toBe('2001-06-01');

    $this->actingAs($this->pv)->get(route('personalverwaltung.member', $kid))->assertOk()
        ->assertSee('Anrechenbare Dienstzeit: 26 Jahre')->assertSee('Jugendfeuerwehr nicht angerechnet');
});

test('Ereignisse with one date and Nachweise at the entries', function () {
    $hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'joined_at' => '2015-04-01']);
    $hanna->moduleAccess()->create(['module' => 'personalverwaltung']);

    $file = Livewire::actingAs($this->pv)->test(MemberFile::class, ['member' => $hanna])
        ->set('stage', ['kind' => 'event', 'label' => 'Verpflichtung', 'since' => '', 'until' => '', 'note' => ''])->call('addStage')->assertHasErrors('history_since')
        ->set('stage', ['kind' => 'event', 'label' => 'Verpflichtung', 'since' => '2015-05-12', 'until' => '2016-01-01', 'note' => ''])->call('addStage')->assertHasNoErrors()
        ->assertSee('Ereignisse')->assertSee('am 12.05.2015');
    $event = HistoryEntry::where('kind', 'event')->sole();
    expect($event->until)->toBeNull()->and($event->isCurrent())->toBeFalse();

    $this->travelBack();   // Livewire's temporary uploads are signed with the real clock
    $file->call('$set', 'attaching', $event->id)
        ->set('upload', UploadedFile::fake()->createWithContent('Verpflichtung.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"))->call('saveFile')->assertHasNoErrors()->assertSee('Verpflichtung.pdf');
    $doc = HistoryFile::sole();

    // The Kamerad sees and opens his own Nachweis, another Kamerad does not
    $this->actingAs($hanna)->get(route('personalverwaltung.own'))->assertSee('Verpflichtung.pdf');
    $this->get(route('personalverwaltung.history-file', $doc))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $stranger = User::factory()->create();
    $stranger->moduleAccess()->create(['module' => 'personalverwaltung']);
    $this->actingAs($stranger)->get(route('personalverwaltung.history-file', $doc))->assertForbidden();

    // An Ereignis can be deleted; its Nachweis goes with it
    Livewire::actingAs($this->pv)->test(MemberFile::class, ['member' => $hanna])->call('deleteStage', $event->id)->assertHasNoErrors();
    expect(HistoryFile::count())->toBe(0);
});
