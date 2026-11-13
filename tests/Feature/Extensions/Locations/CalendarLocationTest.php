<?php

use App\Models\MemberFunction;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Kalender\src\Livewire\EventDetail;
use Modules\Kalender\src\Livewire\EventForm;
use Modules\Kalender\src\Livewire\PollForm;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Notifications\EventCreatedNotification;
use Modules\Kalender\src\Services\EventService;
use Modules\Kalender\src\Services\PollService;

require_once __DIR__.'/../../Kalender/Helpers.php';

function calendarLocationMember(Location $location, string $name): User
{
    $user = User::factory()->create(['name' => $name, 'ext_location_id' => $location->id]);
    DB::table('x_location_user')->insertOrIgnore(['location_id' => $location->id, 'user_id' => $user->id, 'role' => 'member']);
    $user->moduleAccess()->create(['module' => 'kalender']);

    return $user;
}

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'kalender')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'kalender-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'kalender-sued']);
    $this->east = Location::create(['name' => 'Wache Ost', 'slug' => 'kalender-ost']);
    $this->northGroup = $this->north->groups()->create(['name' => 'Jugendfeuerwehr']);
    $this->southGroup = $this->south->groups()->create(['name' => 'Jugendfeuerwehr']);
    $this->southMember = calendarLocationMember($this->south, 'Bernd Blaulicht');
    $this->eastMember = calendarLocationMember($this->east, 'Hanna Hydrant');
    $this->roles = app(StandardRoleAssignments::class);
});

it('limits local Wehrführung calendar management to assigned locations and groups', function () {
    $manager = calendarLocationMember($this->north, 'Tina Teamleitung');
    DB::table('x_location_user')->insertOrIgnore(['location_id' => $this->south->id, 'user_id' => $manager->id, 'role' => 'member']);
    $this->roles->grant($this->admin, $manager->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');
    $northMember = calendarLocationMember($this->north, 'Mara Muster');

    $event = app(EventService::class)->create([
        'title' => 'Übungsdienst Nord', 'type' => 'uebung',
        'starts_at' => Carbon::parse('2027-07-14 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-07-14 21:00', 'Europe/Berlin'),
    ], [$this->northGroup->id], $manager, locationIds: [$this->north->id])->first();
    $southEvent = app(EventService::class)->create([
        'title' => 'Übungsdienst Süd', 'type' => 'uebung',
        'starts_at' => Carbon::parse('2027-07-15 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-07-15 21:00', 'Europe/Berlin'),
    ], [$this->southGroup->id], $this->admin, locationIds: [$this->south->id])->first();

    expect($manager->canUseModule('kalender'))->toBeTrue()
        ->and($manager->canManageKalenderAt($this->north->id))->toBeTrue()
        ->and($manager->canManageKalenderAt($this->south->id))->toBeFalse()
        ->and($manager->canManageKalenderLocations([$this->north->id, $this->south->id]))->toBeFalse();

    expect(Gate::forUser($manager)->allows('manage', $event))->toBeTrue()
        ->and(Gate::forUser($manager)->allows('manage', $southEvent))->toBeFalse();
    expect(Event::visibleTo($manager)->pluck('id')->all())->toContain($event->id)->not->toContain($southEvent->id);

    Livewire::actingAs($manager)->test(EventForm::class)
        ->assertSee('Wache Nord')
        ->assertDontSee('Wache Süd')
        ->assertSee('Jugendfeuerwehr (Wache Nord)')
        ->assertDontSee('Jugendfeuerwehr (Wache Süd)');
});

it('stores multi-location events and polls, restricts audience and carries locations into the chosen event', function () {
    Notification::fake();
    $manager = kalenderUser('terminverwalter', ['name' => 'Tina Terminverwalter']);
    $northMember = calendarLocationMember($this->north, 'Mara Muster');
    $northMember->groups()->attach($this->northGroup);
    $this->southMember->groups()->attach($this->southGroup);
    $this->actingAs($manager);

    $event = app(EventService::class)->create([
        'title' => 'Gemeinsame Übung', 'type' => 'uebung',
        'starts_at' => Carbon::parse('2027-07-14 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-07-14 21:00', 'Europe/Berlin'),
    ], [$this->northGroup->id, $this->southGroup->id], $manager, notify: true, locationIds: [$this->north->id, $this->south->id])->first();

    expect($event->locationIds())->toBe([$this->north->id, $this->south->id])
        ->and(app(EventService::class)->audience($event)->pluck('id')->all())->toContain($northMember->id, $this->southMember->id)
        ->and(app(EventService::class)->audience($event)->pluck('id')->all())->not->toContain($this->eastMember->id)
        ->and(app(EventService::class)->participantRows($event)->pluck('user.id')->all())->toContain($this->southMember->id);
    Notification::assertSentTo([$northMember, $this->southMember], EventCreatedNotification::class);
    Notification::assertNotSentTo($this->eastMember, EventCreatedNotification::class);
    Livewire::actingAs($manager)->test(EventForm::class, ['event' => $event])
        ->assertSet('locationIds', [(string) $this->north->id, (string) $this->south->id])
        ->assertSet('groupIds', [(string) $this->northGroup->id, (string) $this->southGroup->id]);
    Livewire::actingAs($manager)->test(EventDetail::class, ['event' => $event])->assertSee($this->southMember->name);

    $options = [
        ['starts_at' => Carbon::parse('2027-07-20 19:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-07-20 21:00', 'Europe/Berlin')],
        ['starts_at' => Carbon::parse('2027-07-21 19:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-07-21 21:00', 'Europe/Berlin')],
    ];
    $poll = app(PollService::class)->create($manager, ['title' => 'Gemeinsamer Termin', 'type' => 'veranstaltung'], $options,
        [$this->northGroup->id, $this->southGroup->id], notify: false, locationIds: [$this->north->id, $this->south->id]);

    expect($poll->locationIds())->toBe([$this->north->id, $this->south->id])
        ->and(app(PollService::class)->audience($poll)->pluck('id')->all())->toContain($northMember->id, $this->southMember->id)
        ->and(app(PollService::class)->audience($poll)->pluck('id')->all())->not->toContain($this->eastMember->id);

    $option = $poll->options()->first();
    app(PollService::class)->vote($this->southMember, $poll, [(string) $option->id => 'yes']);
    expect(app(PollService::class)->results($poll)['options']->first()['yes']->pluck('id')->all())
        ->toContain($this->southMember->id);

    app(PollService::class)->decide($manager, $poll, $poll->options()->first(), notify: false);
    $decidedEvent = Event::findOrFail($poll->fresh()->event_id);
    expect($decidedEvent->locationIds())->toBe([$this->north->id, $this->south->id]);

    Livewire::actingAs($manager)->test(PollForm::class)
        ->set('locationIds', [(string) $this->north->id])
        ->assertSee('Jugendfeuerwehr (Wache Nord)')
        ->assertDontSee('Jugendfeuerwehr (Wache Süd)');
});

it('rejects groups, invitees and management locations outside the selected event locations', function () {
    $manager = calendarLocationMember($this->north, 'Tina Teamleitung');
    $this->roles->grant($this->admin, $manager->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');
    $base = [
        'title' => 'Übungsdienst', 'type' => 'uebung',
        'starts_at' => Carbon::parse('2027-07-14 19:00', 'Europe/Berlin'),
        'ends_at' => Carbon::parse('2027-07-14 21:00', 'Europe/Berlin'),
    ];

    expect(fn () => app(EventService::class)->create($base, [$this->southGroup->id], $manager, locationIds: [$this->north->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(EventService::class)->create($base, [], $manager, userIds: [$this->southMember->id], locationIds: [$this->north->id]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(EventService::class)->create($base, [], $manager, locationIds: [$this->south->id]))
        ->toThrow(ValidationException::class);
});

it('lets a Jugendwart keep the Termine of the own Abteilung and nobody else\'s', function () {
    $lead = calendarLocationMember($this->north, 'Jule Jugendwart');
    $this->roles->grant($this->admin, $lead->id, 'jugendwart', $this->north->id, $this->northGroup->id, 'Jugendwartin Nord');
    $einsatz = $this->north->groups()->create(['name' => 'Einsatzabteilung']);
    $function = MemberFunction::create(['name' => 'Maschinist']);
    $when = fn (string $day) => ['type' => 'uebung',
        'starts_at' => Carbon::parse($day.' 17:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse($day.' 19:00', 'Europe/Berlin')];
    $events = app(EventService::class);

    $own = $events->create(['title' => 'Jugendübung', ...$when('2027-07-14')], [$this->northGroup->id], $lead, locationIds: [$this->north->id])->first();
    $other = $events->create(['title' => 'Einsatzübung', ...$when('2027-07-15')], [$einsatz->id], $this->admin, locationIds: [$this->north->id])->first();
    $south = $events->create(['title' => 'Jugendübung Süd', ...$when('2027-07-16')], [$this->southGroup->id], $this->admin, locationIds: [$this->south->id])->first();

    expect($lead->canManageAnyKalenderLocation())->toBeFalse()
        ->and($lead->canManageKalenderAt($this->north->id))->toBeFalse()
        ->and(Gate::forUser($lead)->allows('manage', Event::class))->toBeTrue()
        ->and(Gate::forUser($lead)->allows('manage', $own))->toBeTrue()
        ->and(Gate::forUser($lead)->allows('manage', $other))->toBeFalse()
        ->and(Gate::forUser($lead)->allows('manage', $south))->toBeFalse();
    // Not a member of the Abteilung, still sees its Termine; not the ones of others
    expect(Event::visibleTo($lead)->pluck('id')->all())->toContain($own->id)->not->toContain($other->id)->not->toContain($south->id);

    // Another Abteilung, all of the Standort, a Funktion or another Standort: refused
    foreach ([[[$einsatz->id], [], [$this->north->id]], [[], [], [$this->north->id]],
        [[$this->northGroup->id, $einsatz->id], [], [$this->north->id]],
        [[$this->northGroup->id], [$function->id], [$this->north->id]],
        [[$this->southGroup->id], [], [$this->south->id]]] as [$groups, $functions, $locations]) {
        expect(fn () => $events->create(['title' => 'Fremd', ...$when('2027-07-20')], $groups, $lead, functionIds: $functions, locationIds: $locations))
            ->toThrow(ValidationException::class);
    }
    expect(fn () => $events->update($own, ['title' => 'Jugendübung', ...$when('2027-07-14')], [$einsatz->id], by: $lead))
        ->toThrow(ValidationException::class);

    Livewire::actingAs($lead)->test(EventForm::class)
        ->assertSet('groupIds', [(string) $this->northGroup->id])
        ->assertSee('Jugendfeuerwehr (Wache Nord)')
        ->assertDontSee('Einsatzabteilung')
        ->assertDontSee('Maschinist');
    Livewire::actingAs($lead)->test(EventForm::class, ['event' => $other])->assertForbidden();
    Livewire::actingAs($lead)->test(EventDetail::class, ['event' => $own])->call('cancelEvent');
    expect($own->refresh()->isCancelled())->toBeTrue();
    // Terminabfragen stay with whoever keeps a whole Standort
    Livewire::actingAs($lead)->test(PollForm::class)->assertForbidden();
});

it('lets the Jugendwart of the Kommune keep the Termine of every Jugendfeuerwehr', function () {
    $lead = calendarLocationMember($this->north, 'Gero Gemeindejugendwart');
    $this->roles->grant($this->admin, $lead->id, 'gemeindejugendwart', null, null, 'Jugendwart der Kommune');
    $when = ['type' => 'uebung', 'starts_at' => Carbon::parse('2027-07-14 17:00', 'Europe/Berlin'), 'ends_at' => Carbon::parse('2027-07-14 19:00', 'Europe/Berlin')];

    $joint = app(EventService::class)->create(['title' => 'Gemeinsame Jugendübung', ...$when],
        [$this->northGroup->id, $this->southGroup->id], $lead, locationIds: [$this->north->id, $this->south->id])->first();
    $south = app(EventService::class)->create(['title' => 'Jugendübung Süd', ...$when], [$this->southGroup->id], $this->admin, locationIds: [$this->south->id])->first();

    expect($joint->locationIds())->toEqualCanonicalizing([$this->north->id, $this->south->id])
        ->and(Gate::forUser($lead)->allows('manage', $south))->toBeTrue();
    // The Termin of a Standort the Jugendwart is no member of still opens
    $this->actingAs($lead);
    expect(Event::visibleTo($lead)->pluck('id')->all())->toContain($joint->id, $south->id);
});
