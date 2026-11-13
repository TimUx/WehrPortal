<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Notifications\DeadlineDigestNotification;
use Modules\Fahrzeuge\src\Services\FleetService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::whereIn('key', ['fahrzeuge', 'ausruestung', 'atemschutz', 'personalverwaltung', 'verbandbuch', 'expositionen'])->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'rollen-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'rollen-sued']);
    $this->roles = app(StandardRoleAssignments::class);
    $this->at = function (Location $location, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['ext_location_id' => $location->id])->save();

        return $user;
    };
});

it('finds who holds a module role through a standard role, per Standort', function () {
    $bernd = ($this->at)($this->north, 'Bernd Blaulicht');
    $hanna = ($this->at)($this->north, 'Hanna Hydrant');
    $gbi = ($this->at)($this->north, 'Gerd Gemeinde');
    $this->roles->grant($this->admin, $bernd->id, 'geraetewart', $this->north->id, null, 'Gerätewart Nord');
    $this->roles->grant($this->admin, $hanna->id, 'atemschutzwart', null, null, 'Atemschutz Gemeinde');
    $this->roles->grant($this->admin, $gbi->id, 'gemeindebrandinspektor', null, null, 'GBI');

    expect($this->roles->userIdsWithModuleRole('fahrzeuge', 'fahrzeugwart', $this->north->id))->toBe([$bernd->id])
        ->and($this->roles->userIdsWithModuleRole('fahrzeuge', 'fahrzeugwart', $this->south->id))->toBe([])
        ->and($this->roles->userIdsWithModuleRole('fahrzeuge', 'fahrzeugwart'))->toBe([])
        // A role for the whole Gemeinde counts at every Standort; the GBI is nobody's substitute recipient
        ->and($this->roles->userIdsWithModuleRole('atemschutz', 'atemschutzwart', $this->south->id))->toBe([$hanna->id])
        ->and($this->roles->userIdsWithModuleRole('atemschutz', 'atemschutzwart'))->toBe([$hanna->id]);

    // Leaving the Standort ends the duty there
    $bernd->forceFill(['ext_location_id' => $this->south->id])->save();
    $bernd->locations()->detach($this->north->id);
    expect($this->roles->userIdsWithModuleRole('fahrzeuge', 'fahrzeugwart', $this->north->id))->toBe([]);
});

it('sends vehicle deadlines to the Gerätewart of the vehicle\'s Standort', function () {
    Notification::fake();
    $bernd = ($this->at)($this->north, 'Bernd Blaulicht');
    $south = ($this->at)($this->south, 'Sven Süd');
    $this->roles->grant($this->admin, $bernd->id, 'geraetewart', $this->north->id, null, 'Gerätewart Nord');
    $this->roles->grant($this->admin, $south->id, 'geraetewart', $this->south->id, null, 'Gerätewart Süd');
    $vehicle = Vehicle::factory()->create(['ext_location_id' => $this->north->id]);
    Deadline::create(['vehicle_id' => $vehicle->id, 'title' => 'HU', 'due_on' => now('Europe/Berlin')->addDays(3)->toDateString(), 'lead_days' => 30, 'is_active' => true]);

    $fleet = app(FleetService::class);
    expect($fleet->managersAt($this->north->id)->pluck('id')->all())->toBe([$bernd->id])
        // Nobody responsible: the admins step in
        ->and($fleet->managersAt(null)->pluck('id')->all())->toBe([$this->admin->id]);

    Notification::fake();
    Deadline::query()->update(['due_mailed_at' => null]);
    $fleet->remind();

    Notification::assertSentTo($bernd, DeadlineDigestNotification::class);
    Notification::assertNotSentTo($south, DeadlineDigestNotification::class);
    Notification::assertNotSentTo($this->admin, DeadlineDigestNotification::class);
});

it('names the classic module roles that do nothing with Standorte', function () {
    $registry = app(ModuleRegistry::class);

    expect(array_keys($registry->ineffectiveRoles('personalverwaltung')))->toBe(['personalverwalter', 'personaleinsicht'])
        ->and(array_keys($registry->ineffectiveRoles('verbandbuch')))->toBe(['wehrführung'])
        ->and(array_keys($registry->ineffectiveRoles('expositionen')))->toBe(['expositionsbeauftragter'])
        ->and($registry->ineffectiveRoles('fahrzeuge'))->toBe([])
        ->and($registry->roleOptions('personalverwaltung')['personaleinsicht'])->toBe('Personaleinsicht (ohne Wirkung)')
        ->and($registry->roleOptions('fahrzeuge'))->toBe(['fahrzeugwart' => 'Fahrzeugwart'])
        ->and($registry->ineffectiveRolesHint('verbandbuch'))->toContain('Standardrolle Wehrführung')
        ->and($registry->ineffectiveRolesHint('fahrzeuge'))->toBeNull();
});

it('keeps the name of an Abteilung that standard roles are matched by', function () {
    $youth = $this->north->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    expect(fn () => $youth->update(['name' => 'JF Nord']))->toThrow(ValidationException::class);
    expect($youth->fresh()->name)->toBe('Jugendfeuerwehr');

    // Other fields and other Abteilungen stay free
    $youth->fresh()->update(['sort_order' => 7]);
    $other = Group::withoutGlobalScopes()->where('ext_location_id', $this->north->id)->where('name', 'Einsatzabteilung')->firstOrFail();
    $other->update(['name' => 'Aktive']);
    expect($other->fresh()->name)->toBe('Aktive');
});

it('says why a standard role can not be given yet', function () {
    $catalog = app(StandardRoleCatalog::class);

    expect($catalog->notAssignableReason('wehrfuehrung'))->toBeNull()
        ->and($catalog->notAssignableReason('buergermeister'))->toContain('LOCATIONS_LEADERSHIP_ENABLED')
        ->and($catalog->notAssignableReason('brandschutzamt'))->toContain('Kleiderkammer')
        ->and($catalog->groupNames())->toBe(['jugendfeuerwehr', 'kinderfeuerwehr']);
});
