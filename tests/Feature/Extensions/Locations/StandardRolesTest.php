<?php

use App\Models\Group;
use App\Models\Module;
use App\Models\Setting;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Anwesenheit\Services\AttendanceService;
use Extensions\Locations\Filament\LeadershipOverviewPage;
use Extensions\Locations\Filament\StandardRolesPage;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Extensions\Locations\Services\ClothingPersonalAccess;
use Extensions\Locations\Services\ClothingStockAccess;
use Extensions\Locations\Services\LeadershipProjections;
use Extensions\Locations\Services\StandardRoleAssignments;
use Extensions\Locations\Services\StandardRoleCatalog;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Ausruestung\src\Filament\Pages\LoadPlanPage;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Livewire\StocktakeForm;
use Modules\Ausruestung\src\Models\Equipment as InventoryEquipment;
use Modules\Ausruestung\src\Models\Location as StorageLocation;
use Modules\Ausruestung\src\Models\Stocktake;
use Modules\Ausruestung\src\Services\EquipmentService;
use Modules\Ausruestung\src\Services\LoadPlanService;
use Modules\Ausruestung\src\Services\StocktakeService;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeuge\src\Models\Deadline;
use Modules\Fahrzeuge\src\Models\Document;
use Modules\Fahrzeuge\src\Services\FleetService;
use Modules\Uebungsdienste\src\Services\ServicePlan;
use Modules\Unterweisungen\src\Services\InstructionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::whereIn('key', ['uebungsdienste', 'anwesenheit', 'fahrzeuge', 'ausruestung', 'einsatzbericht', 'atemschutz', 'unterweisungen'])->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();

    $this->admin = User::factory()->admin()->create();
    $this->member = User::factory()->create(['ext_location_id' => null]);
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'standard-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'standard-sued']);
    $this->member->forceFill(['ext_location_id' => $this->north->id])->save();
    $this->youthNorth = $this->north->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $this->youthSouth = $this->south->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    $this->childrenNorth = $this->north->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    $this->childrenSouth = $this->south->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    $this->roles = app(StandardRoleAssignments::class);
});

it('allows only global admins to grant and revoke standard role assignments', function () {
    expect(fn () => $this->roles->grant($this->member, $this->member->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Aufgabe'))
        ->toThrow(HttpException::class);

    $grant = $this->roles->grant($this->admin, $this->member->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Leitung Jugend');
    expect(fn () => $this->roles->revoke($this->member, $grant->id, 'Aufgabe beendet'))->toThrow(HttpException::class);
    $this->roles->revoke($this->admin, $grant->id, 'Aufgabe beendet');

    expect($grant->fresh()->revoked_source)->toBe('admin')
        ->and($grant->fresh()->revoke_reason)->toBe('Aufgabe beendet');
});

it('provides an admin-only page to assign standard role profiles', function () {
    Livewire::actingAs($this->admin)->test(StandardRolesPage::class)
        ->set('data.user_id', $this->member->id)
        ->set('data.profile', 'jugendwart')
        ->set('data.location_id', $this->north->id)
        ->set('data.group_id', $this->youthNorth->id)
        ->set('data.reason', 'Jugenddienstplanung')
        ->call('assign')
        ->assertHasNoErrors();

    expect(StandardRoleAssignment::where('user_id', $this->member->id)->where('profile', 'jugendwart')->exists())->toBeTrue();
    Livewire::actingAs($this->member)->test(StandardRolesPage::class)->assertForbidden();
});

it('checks group-scoped profiles against the exact target group and location', function () {
    $this->roles->grant($this->admin, $this->member->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Leitung Jugend');
    $plan = app(ServicePlan::class);
    $youthService = $plan->add($this->admin, '2027-04-06', '19:00', 'Wache', [
        'target_location_ids' => [$this->north->id],
        'group_ids' => [$this->youthNorth->id],
    ]);
    $childrenService = $plan->add($this->admin, '2027-04-13', '19:00', 'Wache', [
        'target_location_ids' => [$this->north->id],
        'group_ids' => [$this->childrenNorth->id],
    ]);

    expect($this->member->canUseModule('uebungsdienste'))->toBeTrue()
        ->and($plan->canManageService($this->member, $youthService))->toBeTrue()
        ->and($plan->canManageService($this->member, $childrenService))->toBeFalse()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'jugendwart', $this->north->id, $this->youthNorth->id))->toBeTrue()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'jugendwart', $this->north->id, $this->childrenNorth->id))->toBeFalse()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'jugendwart', $this->south->id, $this->youthSouth->id))->toBeFalse()
        ->and($this->member->hasModuleRole('uebungsdienste', 'jugendwart', $this->north->id))->toBeFalse()
        ->and($this->member->standardRoleGroupIds('uebungsdienste', ['jugendwart']))->toBe([$this->youthNorth->id]);
});

it('allows global youth wardens to be assigned by exact youth groups across sites', function () {
    $this->roles->grant($this->admin, $this->member->id, 'gemeindejugendwart', null, $this->youthSouth->id, 'Gemeindeweite Jugendplanung');

    expect($this->member->hasModuleRoleForGroup('uebungsdienste', 'gemeindejugendwart', $this->south->id, $this->youthSouth->id))->toBeTrue()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'gemeindejugendwart', $this->north->id, $this->youthNorth->id))->toBeFalse()
        ->and($this->member->standardRoleLocationIds('uebungsdienste', ['gemeindejugendwart']))->toBe([$this->south->id]);

    $planner = app(ServicePlan::class);
    expect($planner->locationOptions($this->member)->keys()->map(fn ($id) => (int) $id)->all())->toBe([$this->south->id])
        ->and($planner->groupOptions($this->member, [$this->south->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $this->youthSouth->id])
        ->and($planner->groupOptions($this->member, [$this->north->id]))->toBeEmpty();

    $service = $planner->add($this->member, '2027-04-06', '19:00', 'Wache', [
        'target_location_ids' => [$this->south->id],
        'group_ids' => [$this->youthSouth->id],
    ]);
    expect($planner->canManageService($this->member, $service))->toBeTrue();
});

it('stops group-scoped standard roles from resolving through inactive or mismatched groups', function () {
    $grant = $this->roles->grant(
        $this->admin,
        $this->member->id,
        'gemeindejugendwart',
        null,
        $this->youthSouth->id,
        'Gemeindeweite Jugendplanung',
    );

    expect($this->member->standardRoleGroupIds('uebungsdienste', ['gemeindejugendwart']))->toBe([$this->youthSouth->id])
        ->and($this->member->standardRoleLocationIds('uebungsdienste', ['gemeindejugendwart']))->toBe([$this->south->id]);

    DB::table('x_locations')->where('id', $this->south->id)->update(['is_active' => false]);

    expect($this->member->standardRoleGroupIds('uebungsdienste', ['gemeindejugendwart']))->toBe([])
        ->and($this->member->standardRoleLocationIds('uebungsdienste', ['gemeindejugendwart']))->toBe([])
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'gemeindejugendwart', $this->south->id, $this->youthSouth->id))->toBeFalse()
        ->and($grant->fresh()->revoked_at)->toBeNull();
});

it('allows municipality youth wardens to manage every matching group across active locations', function () {
    $this->roles->grant($this->admin, $this->member->id, 'gemeindejugendwart', null, null, 'Gemeindeweite Jugendplanung');
    $planner = app(ServicePlan::class);
    $youthNorth = $planner->add($this->admin, '2027-04-06', '19:00', 'Nord', [
        'target_location_ids' => [$this->north->id],
        'group_ids' => [$this->youthNorth->id],
    ]);
    $youthSouth = $planner->add($this->admin, '2027-04-13', '19:00', 'Süd', [
        'target_location_ids' => [$this->south->id],
        'group_ids' => [$this->youthSouth->id],
    ]);
    $children = $planner->add($this->admin, '2027-04-20', '19:00', 'Nord', [
        'target_location_ids' => [$this->north->id],
        'group_ids' => [$this->childrenNorth->id],
    ]);

    $main = Location::default();
    $youthMain = $main->groups()->where('name', 'Jugendfeuerwehr')->firstOrFail();
    expect($planner->locationOptions($this->member)->keys()->map(fn ($id) => (int) $id)->all())->toBe([$main->id, $this->north->id, $this->south->id])
        ->and($planner->groupOptions($this->member, [$main->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $youthMain->id])
        ->and($planner->groupOptions($this->member, [$this->north->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $this->youthNorth->id])
        ->and($planner->groupOptions($this->member, [$this->south->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $this->youthSouth->id])
        ->and($planner->canManageService($this->member, $youthNorth))->toBeTrue()
        ->and($planner->canManageService($this->member, $youthSouth))->toBeTrue()
        ->and($planner->canManageService($this->member, $children))->toBeFalse()
        ->and($this->member->hasModuleRole('uebungsdienste', 'gemeindejugendwart', $this->north->id))->toBeFalse()
        ->and($this->member->standardRoleGroupIds('uebungsdienste', ['gemeindejugendwart']))->toContain($this->youthNorth->id, $this->youthSouth->id);
});

it('allows municipality children wardens to manage every matching group across active locations', function () {
    $childrenWarden = User::factory()->create(['name' => 'Karla Kinderwart', 'ext_location_id' => null]);
    $this->roles->grant($this->admin, $childrenWarden->id, 'gemeindekinderwart', null, null, 'Gemeindeweite Kinderplanung');
    $planner = app(ServicePlan::class);
    $north = $planner->add($this->admin, '2027-04-06', '19:00', 'Nord', [
        'target_location_ids' => [$this->north->id],
        'group_ids' => [$this->childrenNorth->id],
    ]);
    $south = $planner->add($this->admin, '2027-04-13', '19:00', 'Süd', [
        'target_location_ids' => [$this->south->id],
        'group_ids' => [$this->childrenSouth->id],
    ]);

    $main = Location::default();
    $childrenMain = $main->groups()->where('name', 'Kinderfeuerwehr')->firstOrFail();
    expect($planner->locationOptions($childrenWarden)->keys()->map(fn ($id) => (int) $id)->all())->toBe([$main->id, $this->north->id, $this->south->id])
        ->and($planner->groupOptions($childrenWarden, [$main->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $childrenMain->id])
        ->and($planner->groupOptions($childrenWarden, [$this->north->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $this->childrenNorth->id])
        ->and($planner->groupOptions($childrenWarden, [$this->south->id])->keys()->map(fn ($id) => (int) $id)->all())->toBe([(int) $this->childrenSouth->id])
        ->and($planner->canManageService($childrenWarden, $north))->toBeTrue()
        ->and($planner->canManageService($childrenWarden, $south))->toBeTrue()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'gemeindekinderwart', $this->north->id, $this->childrenNorth->id))->toBeFalse()
        ->and($childrenWarden->standardRoleGroupIds('uebungsdienste', ['gemeindekinderwart']))->toContain($this->childrenNorth->id, $this->childrenSouth->id);
});

it('applies Wehrführung attendance permissions only at its assigned location and GBI across active locations', function () {
    $this->roles->grant($this->admin, $this->member->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');
    $attendance = app(AttendanceService::class);
    expect($attendance->canManageAt($this->member, $this->north->id))->toBeTrue()
        ->and($attendance->canManageAt($this->member, $this->south->id))->toBeFalse()
        ->and($attendance->canManage($this->member))->toBeFalse()
        ->and($this->member->canUseModule('einsatzbericht'))->toBeTrue();

    $globalLeader = User::factory()->create(['name' => 'Gisela Gemeindewehrführung', 'ext_location_id' => null]);
    $this->roles->grant($this->admin, $globalLeader->id, 'gemeindebrandinspektor', null, null, 'Gemeindeweite Leitung');
    expect($attendance->canManageAt($globalLeader, $this->north->id))->toBeTrue()
        ->and($attendance->canManageAt($globalLeader, $this->south->id))->toBeTrue()
        ->and($attendance->canManage($globalLeader))->toBeTrue();
});

it('gives the global GBI access to active modules and location-scoped records at every site', function () {
    $this->roles->grant($this->admin, $this->member->id, 'gemeindebrandinspektor', null, null, 'Gemeindeweite Gesamtverantwortung');
    $this->actingAs($this->member);

    expect($this->member->canUseModule('fahrzeuge'))->toBeTrue()
        ->and($this->member->hasModuleRole('fahrzeuge', 'fahrzeugwart', $this->south->id))->toBeTrue()
        ->and(Group::query()->whereKey($this->youthSouth->id)->exists())->toBeTrue()
        ->and(InstructionService::canManageAll($this->member))->toBeTrue();
});

it('rejects mismatched role targets and enforces a reason for every grant', function () {
    expect(fn () => $this->roles->grant($this->admin, $this->member->id, 'jugendwart', $this->north->id, $this->childrenNorth->id, 'Falsche Gruppe'))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->roles->grant($this->admin, $this->member->id, 'wehrfuehrung', null, null, 'Fehlender Standort'))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->roles->grant($this->admin, $this->member->id, 'jugendwart', $this->north->id, $this->youthNorth->id, ' '))
        ->toThrow(ValidationException::class);
});

it('irrevocably revokes a local standard role when its last location membership is removed', function () {
    $grant = $this->roles->grant($this->admin, $this->member->id, 'wehrfuehrung', $this->north->id, null, 'Standortleitung');
    DB::table('users')->where('id', $this->member->id)->update(['ext_location_id' => null]);

    expect($grant->fresh()->revoked_source)->toBe('membership')
        ->and($this->member->fresh()->hasModuleRole('anwesenheit', 'verwalter', $this->north->id))->toBeFalse()
        ->and(StandardRoleAssignment::count())->toBe(1);
});

it('does not apply role permissions when the target module is inactive', function () {
    $this->roles->grant($this->admin, $this->member->id, 'jugendwart', $this->north->id, $this->youthNorth->id, 'Leitung Jugend');
    Module::where('key', 'uebungsdienste')->update(['is_active' => false]);
    app(ModuleRegistry::class)->clearCache();

    expect($this->member->canUseModule('uebungsdienste'))->toBeFalse()
        ->and($this->member->hasModuleRoleForGroup('uebungsdienste', 'jugendwart', $this->north->id, $this->youthNorth->id))->toBeFalse();
});

it('grants module access through the global Atemschutzwart standard profile', function () {
    $this->roles->grant($this->admin, $this->member->id, 'atemschutzwart', null, null, 'Globaler Atemschutzwart');

    expect($this->member->canUseModule('atemschutz'))->toBeTrue()
        ->and($this->member->hasModuleRole('atemschutz', 'atemschutzwart'))->toBeTrue()
        ->and($this->member->canUseModule('unterweisungen'))->toBeTrue()
        ->and($this->member->hasModuleRole('unterweisungen', 'unterweisungsbeauftragter'))->toBeTrue();
});

it('releases Brandschutzamt only with protected cross-location material and clothing management', function () {
    $catalog = app(StandardRoleCatalog::class);
    expect($catalog->isAssignable('brandschutzamt'))->toBeFalse();

    config(['locations.clothing_stock_enabled' => true]);
    expect($catalog->isAssignable('brandschutzamt'))->toBeFalse();

    config(['locations.clothing_personal_enabled' => true]);
    expect($catalog->isAssignable('brandschutzamt'))->toBeFalse();

    Module::whereIn('key', ['ausruestung', 'kleiderkammer'])->update(['is_active' => true, 'auto_assign' => false]);
    app(ModuleRegistry::class)->clearCache();
    expect($catalog->isAssignable('brandschutzamt'))->toBeTrue();

    $otherMember = User::factory()->create(['ext_location_id' => $this->south->id]);
    $this->roles->grant($this->admin, $this->member->id, 'brandschutzamt', null, null, 'Gemeindeweite Material- und Kleiderkammerverwaltung');
    $this->actingAs($this->member);

    expect($this->member->canUseModule('ausruestung'))->toBeTrue()
        ->and($this->member->hasModuleRole('ausruestung', 'geraetewart', $this->south->id))->toBeTrue()
        ->and(EquipmentService::equipmentManagementScope($this->member))->toBeNull()
        ->and($this->member->canUseModule('kleiderkammer'))->toBeTrue()
        ->and(app(ClothingStockAccess::class)->isGlobal($this->member))->toBeTrue()
        ->and(app(ClothingPersonalAccess::class)->isGlobal($this->member))->toBeTrue()
        ->and(app(ClothingPersonalAccess::class)->members($this->member)->whereKey($otherMember->id)->exists())->toBeTrue();

    $assignment = StandardRoleAssignment::where('user_id', $this->member->id)->where('profile', 'brandschutzamt')->sole();
    $this->roles->revoke($this->admin, $assignment->id, 'Aufgabe beendet');
    expect($this->member->fresh()->canUseModule('ausruestung'))->toBeFalse()
        ->and($this->member->fresh()->canUseModule('kleiderkammer'))->toBeFalse();
});

it('gives the global Bürgermeister role only aggregated annual incident totals across active locations', function () {
    $catalog = app(StandardRoleCatalog::class);
    expect($catalog->isAssignable('buergermeister'))->toBeFalse();
    config(['locations.leadership_enabled' => true]);
    expect($catalog->isAssignable('buergermeister'))->toBeTrue();

    $this->roles->grant($this->admin, $this->member->id, 'buergermeister', null, null, 'Kommunale Jahresübersicht');
    $year = now('Europe/Berlin')->year;
    Setting::set('einsatzbericht.released_only', '1');
    foreach ([$this->north, $this->south] as $index => $location) {
        DB::table('eb_reports')->insert([
            'number' => 'BM-'.$index,
            'ext_location_id' => $location->id,
            'alarmed_at' => now(),
            'status' => 'released',
            'address' => 'Vertrauliche Adresse',
            'situation' => 'Vertraulicher Einsatzbericht',
        ]);
    }

    expect($this->member->canUseModule('einsatzbericht'))->toBeFalse()
        ->and(LeadershipOverviewPage::allows($this->member))->toBeTrue();
    expect(fn () => app(LeadershipProjections::class)->read($this->member, $this->north->id, 'equipment_summary'))
        ->toThrow(HttpException::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::actingAs($this->member)->test(LeadershipOverviewPage::class)
        ->assertSee('Wache Nord')->assertSee('Wache Süd')->assertSee((string) $year)
        ->assertDontSee('Vertrauliche Adresse')->assertDontSee('Vertraulicher Einsatzbericht');
});

it('assigns Gerätewart per Standort and limits vehicle and equipment access', function () {
    $this->south->members()->attach($this->member->id, ['role' => 'member']);
    $this->roles->grant($this->admin, $this->member->id, 'geraetewart', $this->north->id, null, 'Standortbezogene Berechtigungsprüfung');

    $northVehicle = Vehicle::factory()->create(['name' => 'LF Nord', 'ext_location_id' => $this->north->id]);
    $southVehicle = Vehicle::factory()->create(['name' => 'LF Süd', 'ext_location_id' => $this->south->id]);
    $fleet = app(FleetService::class);

    expect($this->member->canUseModule('fahrzeuge'))->toBeTrue()
        ->and(FleetService::canManage($this->member))->toBeTrue()
        ->and(FleetService::canManageVehicle($this->member, $northVehicle))->toBeTrue()
        ->and(FleetService::canManageVehicle($this->member, $southVehicle))->toBeFalse()
        ->and(FleetService::vehiclesFor($this->member)->pluck('id')->all())->toBe([$northVehicle->id]);

    expect(fn () => $fleet->setStatus($this->member, $southVehicle, 'unavailable', 'Prüfung'))
        ->toThrow(ValidationException::class);
    expect(fn () => $fleet->saveVehicle($this->member, $northVehicle, ['name' => 'LF Nord', 'ext_location_id' => $this->south->id]))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->member)->get(route('fahrzeuge.show', $southVehicle))->assertNotFound();
    $this->actingAs($this->member)->get(route('fahrzeuge.index'))->assertOk()->assertSee('LF Nord')->assertDontSee('LF Süd');

    $northOnly = User::factory()->create(['ext_location_id' => $this->north->id]);
    StandardRoleAssignment::create([
        'user_id' => $northOnly->id,
        'profile' => 'geraetewart',
        'location_id' => $this->north->id,
        'group_id' => null,
        'granted_by' => $this->admin->id,
        'granted_at' => now(),
        'reason' => 'Standortbezogene Berechtigungsprüfung',
    ]);
    $deadline = Deadline::create(['vehicle_id' => $southVehicle->id, 'title' => 'HU', 'due_on' => '2027-06-01', 'is_active' => true]);
    $document = Document::create(['vehicle_id' => $southVehicle->id, 'kind' => 'manual', 'title' => 'Anleitung', 'name' => 'anleitung.pdf',
        'mime' => 'application/pdf', 'size' => 3, 'data' => base64_encode('pdf'), 'uploaded_by' => $this->admin->id]);

    expect(FleetService::canSeeDocument($northOnly, $document))->toBeFalse();
    expect(fn () => $fleet->deleteDeadline($northOnly, $deadline))->toThrow(ValidationException::class);
    expect(fn () => $fleet->deleteDocument($northOnly, $document))->toThrow(ValidationException::class);
    expect($deadline->fresh())->not->toBeNull()->and($document->fresh())->not->toBeNull();

    $this->actingAs($this->admin);
    $northRoom = StorageLocation::create(['name' => 'Nord Geräteraum', 'kind' => 'room', 'ext_location_id' => $this->north->id]);
    $southRoom = StorageLocation::create(['name' => 'Süd Geräteraum', 'kind' => 'room', 'ext_location_id' => $this->south->id]);
    $northEquipment = InventoryEquipment::create(['name' => 'Pumpe Nord', 'location_id' => $northRoom->id, 'ext_location_id' => $this->north->id]);
    $southEquipment = InventoryEquipment::create(['name' => 'Pumpe Süd', 'location_id' => $southRoom->id, 'ext_location_id' => $this->south->id]);
    $this->actingAs($this->member);
    $equipmentService = app(EquipmentService::class);
    DB::table('ar_equipment')->where('id', $southEquipment->id)->update(['location_id' => $northRoom->id]);

    expect($this->member->canUseModule('ausruestung'))->toBeTrue()
        ->and($this->member->canManageAusruestung())->toBeTrue()
        ->and(EquipmentService::canManageGlobal($this->member))->toBeFalse()
        ->and(EquipmentService::canManageEquipment($this->member, $northEquipment))->toBeTrue()
        ->and(EquipmentService::canManageEquipment($this->member, $southEquipment))->toBeFalse()
        ->and(EquipmentService::equipmentFor($this->member)->pluck('id')->all())->toBe([$northEquipment->id]);

    $this->member->moduleAccess()->create(['module' => 'ausruestung']);
    expect(EquipmentService::equipmentLocationScope($this->member))->toBeNull()
        ->and(EquipmentService::equipmentManagementScope($this->member))->toBe([$this->north->id])
        ->and(EquipmentService::managedEquipmentFor($this->member)->pluck('id')->all())->toBe([$northEquipment->id])
        ->and(EquipmentService::equipmentFor($this->member)->pluck('id')->all())->toEqualCanonicalizing([$northEquipment->id, $southEquipment->id]);

    expect(app(LoadPlanService::class)->unassigned($northRoom)->pluck('id')->all())
        ->not->toContain($southEquipment->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->member)->get('/admin/ausruestung/standorte/'.$northRoom->id.'/bearbeiten')->assertOk();
    $this->actingAs($this->member)->get('/admin/ausruestung/standorte/'.$southRoom->id.'/bearbeiten')->assertNotFound();
    Livewire::actingAs($this->member)->test(LoadPlanPage::class)
        ->assertSee('Nord Geräteraum')
        ->assertDontSee('Süd Geräteraum')
        ->set('location', $southRoom->id)
        ->assertDontSee('Süd Geräteraum');
    expect(EquipmentResource::getEloquentQuery()->pluck('id')->all())->toBe([$northEquipment->id]);

    Livewire::actingAs($this->member)->test(StocktakeForm::class)
        ->set('locationId', (string) $northRoom->id)
        ->set('code', $southEquipment->code)->call('scan')
        ->assertSee('ist unbekannt')
        ->assertDontSee('Pumpe Süd');

    expect(fn () => $equipmentService->moveStandort($northEquipment, $this->member, $this->south->id))
        ->toThrow(ValidationException::class);
    expect(fn () => app(StocktakeService::class)->finish(
        $this->member,
        $northRoom,
        [$northEquipment->id, $southEquipment->id],
        standortId: $this->north->id,
    ))->toThrow(ValidationException::class);
    expect(Stocktake::count())->toBe(0)
        ->and($southEquipment->fresh()->last_seen_at)->toBeNull();

    $stocktake = app(StocktakeService::class)->finish(
        $this->member,
        $northRoom,
        [$northEquipment->id],
        standortId: $this->north->id,
    );
    expect($stocktake->ext_location_id)->toBe($this->north->id)
        ->and($stocktake->expected)->toBe(1)
        ->and($stocktake->found)->toBe([$northEquipment->id]);

    $this->actingAs($this->admin);
    $southStocktake = app(StocktakeService::class)->finish($this->admin, $southRoom, []);
    $this->actingAs($this->member);
    expect($southStocktake->ext_location_id)->toBe($this->south->id)
        ->and(Stocktake::query()->pluck('id')->all())->toBe([$stocktake->id]);
    $this->actingAs($this->admin);
    expect(Stocktake::query()->pluck('id')->all())->toEqualCanonicalizing([$stocktake->id, $southStocktake->id]);

    $this->actingAs($this->member)->get(route('ausruestung.show', $southEquipment))->assertOk()->assertSee('Pumpe Süd');
});

it('gives local Wehrführung vehicle and equipment management only at its assigned Standort', function () {
    $leader = User::factory()->create(['ext_location_id' => $this->north->id]);
    $this->south->members()->attach($leader->id, ['role' => 'member']);
    $this->roles->grant($this->admin, $leader->id, 'wehrfuehrung', $this->north->id, null, 'Standortbezogene Leitung');

    $northVehicle = Vehicle::factory()->create(['name' => 'LF Nord', 'ext_location_id' => $this->north->id]);
    $southVehicle = Vehicle::factory()->create(['name' => 'LF Süd', 'ext_location_id' => $this->south->id]);
    $northRoom = StorageLocation::create(['name' => 'Nord Geräteraum', 'kind' => 'room', 'ext_location_id' => $this->north->id]);
    $southRoom = StorageLocation::create(['name' => 'Süd Geräteraum', 'kind' => 'room', 'ext_location_id' => $this->south->id]);
    $northEquipment = InventoryEquipment::create(['name' => 'Pumpe Nord', 'location_id' => $northRoom->id, 'ext_location_id' => $this->north->id]);
    $southEquipment = InventoryEquipment::create(['name' => 'Pumpe Süd', 'location_id' => $southRoom->id, 'ext_location_id' => $this->south->id]);

    expect($leader->canUseModule('fahrzeuge'))->toBeTrue()
        ->and(FleetService::canManageVehicle($leader, $northVehicle))->toBeTrue()
        ->and(FleetService::canManageVehicle($leader, $southVehicle))->toBeFalse()
        ->and($leader->canUseModule('ausruestung'))->toBeTrue()
        ->and($leader->canManageAusruestung())->toBeTrue()
        ->and(EquipmentService::canManageEquipment($leader, $northEquipment))->toBeTrue()
        ->and(EquipmentService::canManageEquipment($leader, $southEquipment))->toBeFalse();
});
