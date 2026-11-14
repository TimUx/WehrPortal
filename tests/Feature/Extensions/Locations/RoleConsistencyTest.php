<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Locations\Filament\StandardRolesPage;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
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

it('gives a Stellvertretung the same rights under its own name, as many as needed', function () {
    $hanna = ($this->at)($this->north, 'Hanna Hydrant');
    $bernd = ($this->at)($this->north, 'Bernd Blaulicht');
    $frieda = ($this->at)($this->north, 'Frieda Funk');
    $holder = $this->roles->grant($this->admin, $hanna->id, 'wehrfuehrung', $this->north->id, null, 'Wehrführerin Nord');
    $first = $this->roles->grant($this->admin, $bernd->id, 'wehrfuehrung', $this->north->id, null, 'Erster Stellvertreter', null, true);
    $second = $this->roles->grant($this->admin, $frieda->id, 'wehrfuehrung', $this->north->id, null, 'Zweite Stellvertreterin', null, true);

    expect($holder->fresh()->roleName())->toBe('Wehrführung')
        ->and($first->fresh()->roleName())->toBe('Stv. Wehrführung')
        ->and($second->fresh()->is_deputy)->toBeTrue()
        ->and($this->roles->hasModuleRoleAt($bernd, 'fahrzeuge', 'fahrzeugwart', $this->north->id))->toBeTrue()
        ->and($this->roles->hasModuleRoleAt($bernd, 'fahrzeuge', 'fahrzeugwart', $this->south->id))->toBeFalse()
        // Mails of the Standort reach holder and deputies alike
        ->and($this->roles->userIdsWithModuleRole('fahrzeuge', 'fahrzeugwart', $this->north->id))
        ->toEqualCanonicalizing([$hanna->id, $bernd->id, $frieda->id]);
});

it('names the Kommune-wide positions after Gemeinde or Stadt', function () {
    $catalog = app(StandardRoleCatalog::class);
    expect($catalog->name('gemeindebrandinspektor'))->toBe('Gemeindebrandinspektor / Stadtbrandinspektor')
        ->and($catalog->inspector())->toBe('GBI/SBI');

    Setting::set(StandardRoleCatalog::MUNICIPALITY_SETTING, 'stadt');
    expect($catalog->name('gemeindebrandinspektor'))->toBe('Stadtbrandinspektor')
        ->and($catalog->name('gemeindebrandinspektor', true))->toBe('Stv. Stadtbrandinspektor')
        ->and($catalog->name('gemeindejugendwart'))->toBe('Stadtjugendwart')
        ->and($catalog->name('gemeindekinderwart'))->toBe('Stadtkinderwart')
        ->and($catalog->name('wehrfuehrung'))->toBe('Wehrführung')
        ->and($catalog->inspector())->toBe('SBI')
        ->and($catalog->municipalityWord())->toBe('Stadt');

    Setting::set(StandardRoleCatalog::MUNICIPALITY_SETTING, 'gemeinde');
    expect($catalog->name('gemeindebrandinspektor'))->toBe('Gemeindebrandinspektor')
        ->and($catalog->name('gemeindejugendwart'))->toBe('Gemeindejugendwart')
        ->and($catalog->inspector())->toBe('GBI');
});

it('lets the admin choose Gemeinde or Stadt and assign two deputies of the Stadtbrandinspektor', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $bernd = ($this->at)($this->north, 'Bernd Blaulicht');
    $frieda = ($this->at)($this->south, 'Frieda Funk');

    $page = Livewire::actingAs($this->admin)->test(StandardRolesPage::class)
        ->callAction('municipality', ['municipality' => 'stadt'])
        ->assertHasNoActionErrors();
    expect(app(StandardRoleCatalog::class)->municipality())->toBe('stadt');

    foreach ([$bernd, $frieda] as $deputy) {
        $page->set('data.user_id', $deputy->id)
            ->set('data.profile', 'gemeindebrandinspektor')
            ->set('data.reason', 'Stellvertretung des SBI')
            ->set('data.is_deputy', true)
            ->call('assign')
            ->assertHasNoErrors();
    }

    expect(StandardRoleAssignment::where('profile', 'gemeindebrandinspektor')->where('is_deputy', true)->count())->toBe(2)
        ->and($this->roles->hasGlobalProfile($frieda, 'gemeindebrandinspektor'))->toBeTrue();
    Livewire::actingAs($this->admin)->test(StandardRolesPage::class)->assertSee('Stv. Stadtbrandinspektor');
});
