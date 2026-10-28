<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Personalverwaltung\src\Livewire\Honors;
use Modules\Personalverwaltung\src\Livewire\MemberList;
use Modules\Personalverwaltung\src\Livewire\NewMembers;
use Modules\Personalverwaltung\src\Models\HonorType;
use Modules\Personalverwaltung\src\Notifications\PersonalDataChangedNotification;
use Modules\Personalverwaltung\src\Services\PersonnelService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::where('key', 'personalverwaltung')->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    Cache::forget('modules_active_keys');

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'personal-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'personal-sued']);
    $this->youthNorth = $this->north->groups()->create(['name' => 'Jugendfeuerwehr']);
    $this->youthSouth = $this->south->groups()->create(['name' => 'Jugendfeuerwehr']);
    $this->childrenNorth = $this->north->groups()->create(['name' => 'Kinderfeuerwehr']);
    $this->activeNorth = $this->north->groups()->create(['name' => 'Einsatzabteilung']);
    $this->roles = app(StandardRoleAssignments::class);
    $this->personnel = app(PersonnelService::class);
});

it('limits local youth and children wardens to the exact assigned groups and their members', function () {
    $warden = User::factory()->create(['name' => 'Jana Jugendwart', 'ext_location_id' => $this->north->id]);
    $grant = $this->roles->grant($this->admin, $warden->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Jugendverwaltung');
    $childrenWarden = User::factory()->create(['name' => 'Karla Kinderwart', 'ext_location_id' => $this->north->id]);
    $this->roles->grant($this->admin, $childrenWarden->id, 'kinderwart', $this->north->id, $this->childrenNorth->id, 'Kinderverwaltung');

    $youthMember = User::factory()->create(['name' => 'Bernd Blaulicht', 'ext_location_id' => $this->north->id]);
    $youthMember->groups()->attach($this->youthNorth);
    $youthMember->groups()->attach($this->activeNorth);
    $otherGroupMember = User::factory()->create(['name' => 'Hanna Hydrant', 'ext_location_id' => $this->north->id]);
    $otherGroupMember->groups()->attach($this->activeNorth);
    $childrenMember = User::factory()->create(['name' => 'Karla Kinderwart', 'ext_location_id' => $this->north->id]);
    $childrenMember->groups()->attach($this->childrenNorth);
    $otherLocationMember = User::factory()->create(['name' => 'Sven Süd', 'ext_location_id' => $this->south->id]);
    $otherLocationMember->groups()->attach($this->youthSouth);

    expect($warden->canUseModule('personalverwaltung'))->toBeTrue()
        ->and(PersonnelService::canManage($warden))->toBeTrue()
        ->and(PersonnelService::scopeOf($warden))->toBe([(int) $this->youthNorth->id])
        ->and(PersonnelService::visibleMembers($warden)->pluck('id')->all())->toBe([$youthMember->id]);

    Livewire::actingAs($warden)->test(MemberList::class)
        ->assertSee('Bernd Blaulicht')
        ->assertDontSee('Hanna Hydrant')
        ->assertDontSee('Karla Kinderwart')
        ->assertDontSee('Sven Süd');

    $newMember = $this->personnel->createMember($warden, [
        'name' => 'Mia Muster',
        'group_ids' => [$this->youthNorth->id],
    ]);
    expect($newMember->groups()->pluck('groups.id')->all())->toBe([(int) $this->youthNorth->id]);
    expect(PersonnelService::visibleMembers($childrenWarden)->pluck('id')->all())->toBe([$childrenMember->id]);

    expect(fn () => $this->personnel->createMember($warden, [
        'name' => 'Lars Lehrgang',
        'group_ids' => [$this->childrenNorth->id],
    ]))->toThrow(ValidationException::class, 'Bitte eine deiner Abteilungen wählen.');

    $this->roles->revoke($this->admin, $grant->id, 'Aufgabe beendet');
    expect(PersonnelService::canManage($warden->fresh()))->toBeFalse()
        ->and(PersonnelService::visibleMembers($warden->fresh())->count())->toBe(0);
});

it('limits global youth and children wardens to matching groups across locations', function () {
    $youthWarden = User::factory()->create(['name' => 'Gisela Gemeindejugendwart', 'ext_location_id' => null]);
    $this->roles->grant($this->admin, $youthWarden->id, 'gemeindejugendwart', null, null, 'Gemeindeweite Jugendverwaltung');

    $northMember = User::factory()->create(['name' => 'Bernd Blaulicht', 'ext_location_id' => $this->north->id]);
    $northMember->groups()->attach($this->youthNorth);
    $southMember = User::factory()->create(['name' => 'Sven Süd', 'ext_location_id' => $this->south->id]);
    $southMember->groups()->attach($this->youthSouth);
    $childrenMember = User::factory()->create(['name' => 'Karla Kinderwart', 'ext_location_id' => $this->north->id]);
    $childrenMember->groups()->attach($this->childrenNorth);
    $childrenSouth = $this->south->groups()->create(['name' => 'Kinderfeuerwehr']);
    $childrenSouthMember = User::factory()->create(['name' => 'Lena Süd', 'ext_location_id' => $this->south->id]);
    $childrenSouthMember->groups()->attach($childrenSouth);

    expect($youthWarden->canUseModule('personalverwaltung'))->toBeTrue()
        ->and(PersonnelService::scopeOf($youthWarden))->toContain($this->youthNorth->id, $this->youthSouth->id)
        ->and(PersonnelService::visibleMembers($youthWarden)->pluck('id')->sort()->values()->all())->toBe(collect([$northMember->id, $southMember->id])->sort()->values()->all())
        ->and(PersonnelService::canManageHonorTypes($youthWarden))->toBeFalse();

    $childrenWarden = User::factory()->create(['name' => 'Karla Gemeindekinderwart', 'ext_location_id' => null]);
    $this->roles->grant($this->admin, $childrenWarden->id, 'gemeindekinderwart', null, null, 'Gemeindeweite Kinderverwaltung');
    expect(PersonnelService::visibleMembers($childrenWarden)->pluck('id')->sort()->values()->all())->toBe(collect([$childrenMember->id, $childrenSouthMember->id])->sort()->values()->all());

    Livewire::actingAs($youthWarden)->test(MemberList::class)
        ->assertSee('Bernd Blaulicht')
        ->assertSee('Jugendfeuerwehr (Wache Nord)')
        ->assertSee('Sven Süd')
        ->assertSee('Jugendfeuerwehr (Wache Süd)')
        ->assertDontSee('Karla Kinderwart')
        ->assertDontSee('Einsatzabteilung');
    $this->actingAs($youthWarden)->get(route('personalverwaltung.member', $northMember))
        ->assertOk()->assertSee('Bernd Blaulicht')->assertSee('Wache Nord');
    Livewire::actingAs($youthWarden)->test(NewMembers::class)
        ->assertSee('Jugendfeuerwehr (Wache Nord)')
        ->assertSee('Jugendfeuerwehr (Wache Süd)')
        ->assertDontSee('Kinderfeuerwehr');
    Livewire::actingAs($youthWarden)->test(Honors::class)
        ->assertDontSee('Übliche Ehrungsarten anlegen')
        ->assertDontSee('Neue Ehrung');

    expect(fn () => $this->personnel->saveHonorType($youthWarden, null, 'Gemeindeweite Ehrung', null))
        ->toThrow(ValidationException::class, 'Ehrungsarten dürfen nur');
});

it('notifies only the managers whose scopes include the member and preserves location-wide authority', function () {
    $member = User::factory()->create(['name' => 'Bernd Blaulicht', 'ext_location_id' => $this->north->id]);
    $member->groups()->attach($this->youthNorth);
    $member->moduleAccess()->create(['module' => 'personalverwaltung']);

    $localWarden = User::factory()->create(['name' => 'Jana Jugendwart', 'ext_location_id' => $this->north->id]);
    $this->roles->grant($this->admin, $localWarden->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Jugendverwaltung');

    $otherWarden = User::factory()->create(['name' => 'Sven Süd', 'ext_location_id' => $this->south->id]);
    $this->roles->grant($this->admin, $otherWarden->id, 'jugendwart', $this->south->id, $this->youthSouth->id, 'Jugendverwaltung');

    $locationLeader = User::factory()->create(['name' => 'Heinz Wehrführung', 'ext_location_id' => $this->north->id]);
    $this->roles->grant($this->admin, $locationLeader->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');

    expect($this->personnel->responsibleManagers($member)->modelKeys())
        ->toContain($this->admin->id, $localWarden->id, $locationLeader->id)
        ->not->toContain($otherWarden->id);

    Notification::fake();
    $this->personnel->saveSettings($this->admin, ['phone' => true, 'self_service' => true]);
    $this->personnel->savePersonalData($member, $member, ['phone' => '0171 1234567']);

    Notification::assertSentTo($localWarden, PersonalDataChangedNotification::class);
    Notification::assertSentTo($locationLeader, PersonalDataChangedNotification::class);
    Notification::assertNotSentTo($otherWarden, PersonalDataChangedNotification::class);
});

it('keeps Ehrungsarten editable for location-wide managers', function () {
    $leader = User::factory()->create(['name' => 'Heinz Wehrführung', 'ext_location_id' => $this->north->id]);
    $this->roles->grant($this->admin, $leader->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');

    expect(PersonnelService::canManageHonorTypes($leader))->toBeTrue();
    $type = $this->personnel->saveHonorType($leader, null, 'Standort-Ehrung', 10);
    expect($type)->toBeInstanceOf(HonorType::class);
});
