<?php

use App\Livewire\Dashboard\Home;
use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Services\EventService;
use Modules\Personalverwaltung\src\Livewire\Access;
use Modules\Personalverwaltung\src\Livewire\Attendance;
use Modules\Personalverwaltung\src\Livewire\Honors;
use Modules\Personalverwaltung\src\Livewire\MemberFile;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Models\HistoryEntry;
use Modules\Personalverwaltung\src\Models\Honor;
use Modules\Personalverwaltung\src\Services\PersonnelService;

beforeEach(function () {
    Module::whereIn('key', ['personalverwaltung', 'kalender'])->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
    $this->travelTo(Carbon::parse('2026-06-10 12:00', 'Europe/Berlin'));

    $this->active = Group::create(['name' => 'Einsatzabteilung', 'sort_order' => 1]);
    $this->youth = Group::create(['name' => 'Jugendfeuerwehr', 'sort_order' => 2]);
    $this->admin = User::factory()->admin()->create(['name' => 'Anna Admin']);
    $this->manager = User::factory()->create(['name' => 'Paul Personal']);
    $this->manager->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);
    $this->jugendwart = User::factory()->create(['name' => 'Jana Jugendwart']);
    $this->jugendwart->moduleAccess()->create(['module' => 'personalverwaltung', 'role' => 'personalverwalter']);

    $this->hanna = User::factory()->create(['name' => 'Hanna Hydrant', 'joined_at' => '2001-04-01', 'rank' => 'Oberfeuerwehrfrau', 'rank_since' => '2015-01-01']);
    $this->hanna->groups()->attach($this->active);
    $this->bernd = User::factory()->create(['name' => 'Bernd Blaulicht', 'joined_at' => '2023-08-01']);
    $this->bernd->groups()->attach($this->youth);
    foreach ([$this->hanna, $this->bernd] as $u) {
        $u->moduleAccess()->create(['module' => 'personalverwaltung']);
        $u->moduleAccess()->create(['module' => 'kalender']);
    }
    $this->p = app(PersonnelService::class);
    app(PersonnelService::class)->setScope($this->admin, $this->jugendwart, [$this->youth->id]);
});

test('the career writes itself: first with the known dates, then every change from today', function () {
    $this->p->syncHistory($this->hanna);
    $rank = HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'rank')->sole();
    expect($rank)->label->toBe('Oberfeuerwehrfrau')->since->toDateString()->toBe('2015-01-01')->until->toBeNull()
        ->and(HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'status')->sole()->since->toDateString())->toBe('2001-04-01');

    $this->travelTo(Carbon::parse('2026-07-01 12:00', 'Europe/Berlin'));
    $this->hanna->update(['rank' => 'Hauptfeuerwehrfrau **']);
    $this->hanna->groups()->sync([$this->youth->id]);
    expect($this->p->syncHistory($this->hanna->fresh()))->toBe(4)
        ->and($rank->fresh()->until->toDateString())->toBe('2026-07-01')
        ->and(HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'group')->whereNull('until')->sole()->label)->toBe('Jugendfeuerwehr')
        ->and($this->p->syncHistory($this->hanna->fresh()))->toBe(0);

    // A former stage from the paper file; the current one can't be deleted
    expect(fn () => $this->p->addHistory($this->manager, $this->hanna, ['kind' => 'rank', 'label' => 'Feuerwehrfrau', 'since' => '2003-01-01']))->toThrow(ValidationException::class, 'braucht ein Ende')
        ->and(fn () => $this->p->addHistory($this->manager, $this->hanna, ['kind' => 'rank', 'label' => 'Feuerwehrfrau', 'since' => '2005-01-01', 'until' => '2003-01-01']))->toThrow(ValidationException::class, 'vor „seit“');
    $this->p->addHistory($this->manager, $this->hanna, ['kind' => 'rank', 'label' => 'Feuerwehrfrau', 'since' => '2003-01-01', 'until' => '2014-12-31']);
    $current = HistoryEntry::where('user_id', $this->hanna->id)->where('kind', 'rank')->whereNull('until')->sole();
    expect(fn () => $this->p->deleteHistory($this->manager, $current))->toThrow(ValidationException::class, 'Mitgliedsdaten');

    Livewire::actingAs($this->manager)->test(MemberFile::class, ['member' => $this->hanna])
        ->assertSee('Feuerwehrfrau')->assertSee('2003 – 31.12.2014', false)->assertSee('Hauptfeuerwehrfrau **')
        ->call('startEdit', $current->id)->set('edit.since', '2026-06-15')->call('saveEdit')->assertSee('seit 15.06.2026');
});

test('a Personalverwalter can be limited to Abteilungen; Kameraden only see their own file', function () {
    Livewire::actingAs($this->manager)->test(MemberList::class)->assertSee('Hanna Hydrant')->assertSee('Bernd Blaulicht');
    Livewire::actingAs($this->jugendwart)->test(MemberList::class)->assertSee('Bernd Blaulicht')->assertDontSee('Hanna Hydrant')->assertSee('deiner Abteilungen');

    $this->actingAs($this->jugendwart)->get(route('personalverwaltung.member', $this->bernd))->assertOk();
    $this->actingAs($this->jugendwart)->get(route('personalverwaltung.member', $this->hanna))->assertForbidden();
    expect(fn () => $this->p->addHonor($this->jugendwart, $this->hanna, ['title' => 'x', 'awarded_on' => '2026-01-01']))->toThrow(ValidationException::class);

    $this->actingAs($this->hanna)->get(route('personalverwaltung.index'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('personalverwaltung.own'))->assertOk()->assertSee('25 Dienstjahre')->assertSee('Oberfeuerwehrfrau');
    $this->actingAs($this->hanna)->get(route('profile'))->assertSee('Laufbahn &amp; Ehrungen', false);

    Livewire::actingAs($this->admin)->test(Access::class)->assertSee('Jana Jugendwart')->assertSet("scopes.{$this->jugendwart->id}", [(string) $this->youth->id])
        ->set("scopes.{$this->jugendwart->id}", [])->call('save', $this->jugendwart->id)->assertSee('Zugriff gespeichert');
    expect(PersonnelService::scopeOf($this->jugendwart->fresh()))->toBe([]);
    $this->actingAs($this->manager)->get(route('personalverwaltung.access'))->assertForbidden();
});

test('Ehrungen due by Dienstjahre for the year, awarded with one click; a card for the Personalverwaltung', function () {
    $ten = $this->p->saveHonorType($this->manager, null, 'Feuerwehr-Ehrenzeichen 10 Jahre', 10);
    $quarter = $this->p->saveHonorType($this->manager, null, 'Feuerwehr-Ehrenzeichen 25 Jahre', 25);
    $this->p->saveHonorType($this->manager, null, 'Leistungsabzeichen Bronze', null);
    expect(fn () => $this->p->saveHonorType($this->manager, null, 'Leistungsabzeichen Bronze', null))->toThrow(ValidationException::class, 'gibt es schon');

    $due = $this->p->dueHonors($this->manager, 2026);
    expect($due->map(fn ($r) => $r['type']->id.'@'.$r['user']->name)->all())->toBe([$ten->id.'@Hanna Hydrant', $quarter->id.'@Hanna Hydrant'])
        ->and($due[0]['overdue'])->toBeTrue()->and($due[1]['reached_on']->toDateString())->toBe('2026-04-01');

    Livewire::actingAs($this->manager)->test(Home::class)->assertSee('2 Ehrungen 2026 fällig');
    Livewire::actingAs($this->manager)->test(Honors::class)->assertSee('schon überfällig')
        ->set('awardDate', '2026-05-30')->call('award', $this->hanna->id, $quarter->id)->assertSee('Feuerwehr-Ehrenzeichen 25 Jahre für Hanna Hydrant eingetragen')
        ->assertSee('Verliehen 2026 (1)');
    expect(Honor::sole())->awarded_on->toDateString()->toBe('2026-05-30')->and($this->p->dueHonors($this->manager, 2026))->toHaveCount(1);

    expect(fn () => $this->p->addHonor($this->manager, $this->hanna, ['title' => 'Ehrennadel', 'awarded_on' => '2027-01-01']))->toThrow(ValidationException::class, 'Zukunft');
    Livewire::actingAs($this->jugendwart)->test(Honors::class)->assertDontSee('Hanna Hydrant');
});

test('Dienstbeteiligung from the Kalender attendance: only Dienste meant for the Kamerad, with attendance taken, after the Eintritt', function () {
    $events = app(EventService::class);
    $make = fn (string $day, string $type = 'uebung', array $groups = []) => $events->create(['title' => 'Dienst '.$day, 'type' => $type,
        'starts_at' => Carbon::parse($day.' 19:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse($day.' 21:00', 'Europe/Berlin')], $groups, $this->admin)->first();

    $a = $make('2026-03-03');
    $b = $make('2026-04-07');
    $c = $make('2026-05-05');                                  // nobody's attendance taken: doesn't count
    $fest = $make('2026-05-12', 'veranstaltung');              // not a Dienst
    $youth = $make('2026-05-19', 'uebung', [$this->youth->id]); // not meant for Hanna
    foreach ([[$a, true], [$b, false], [$fest, true]] as [$event, $here]) {
        $events->setAttendance($event, $this->hanna, $here, $this->admin);
    }
    $events->setAttendance($youth, $this->bernd, true, $this->admin);

    expect($this->p->attendance($this->hanna, 2026))->toBe(['total' => 2, 'attended' => 1, 'percent' => 50])
        ->and($this->p->attendance($this->bernd, 2026))->toBe(['total' => 3, 'attended' => 1, 'percent' => 33]);

    Livewire::actingAs($this->manager)->test(Attendance::class)->assertSee('1 / 2')->assertSee('50 %')
        ->set('types', ['uebung', 'veranstaltung'])->assertSee('2 / 3');
    $csv = $this->actingAs($this->manager)->get(route('personalverwaltung.attendance.csv', ['jahr' => 2026]))->assertOk()->getContent();
    expect($csv)->toContain('"Hanna Hydrant";Einsatzabteilung;2;1;50');
});

test('the Dienstausweis is kept; one running out shows on the start page', function () {
    Livewire::actingAs($this->manager)->test(MemberFile::class, ['member' => $this->bernd])
        ->set('idCard.number', 'FW-0815')->set('idCard.valid_until', '2026-07-31')->call('saveIdCard')->assertSee('Dienstausweis gespeichert');

    Livewire::actingAs($this->manager)->test(Home::class)->assertSee('1 Dienstausweis läuft bald ab');
    $this->actingAs($this->bernd)->get(route('personalverwaltung.own'))->assertSee('Nr. FW-0815')->assertSee('31.07.2026');
});
