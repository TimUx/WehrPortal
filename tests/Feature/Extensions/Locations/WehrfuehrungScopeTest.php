<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionDetail;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionIndex;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;
use Modules\Fuehrerscheine\src\Livewire\Review;
use Modules\Fuehrerscheine\src\Models\License;
use Modules\Fuehrerscheine\src\Services\LicenseService;
use Modules\Lehrgaenge\src\Livewire\MemberProfile;
use Modules\Lehrgaenge\src\Livewire\Qualifications;
use Modules\Lehrgaenge\src\Livewire\Registrations;
use Modules\Lehrgaenge\src\Models\Course;
use Modules\Lehrgaenge\src\Models\Record;
use Modules\Lehrgaenge\src\Notifications\RequestReceivedNotification;
use Modules\Lehrgaenge\src\Services\RegistrationService;
use Modules\Objektplaene\src\Services\ObjectPlanService;
use Modules\Schluessel\src\Services\KeyService;
use Modules\Unterweisungen\src\Livewire\SessionDetail;
use Modules\Unterweisungen\src\Livewire\Topics;
use Modules\Unterweisungen\src\Models\Topic;
use Modules\Unterweisungen\src\Services\InstructionService;
use Modules\Wasserkarte\src\Services\WaterService;

beforeEach(function () {
    config(['locations.enabled' => true]);
    Module::whereIn('key', ['fahrzeugbuchung', 'hydrantenkontrolle'])->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();

    $this->admin = User::factory()->admin()->create();
    $this->north = Location::create(['name' => 'Wache Nord', 'slug' => 'wf-nord']);
    $this->south = Location::create(['name' => 'Wache Süd', 'slug' => 'wf-sued']);
    $at = function (Location $location, string $name): User {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['ext_location_id' => $location->id])->save();

        return $user;
    };
    $this->hanna = $at($this->north, 'Hanna Hydrant');
    $this->bernd = $at($this->north, 'Bernd Blaulicht');
    app(StandardRoleAssignments::class)->grant($this->admin, $this->hanna->id, 'wehrfuehrung', $this->north->id, null, 'Wehrführerin Nord');

    // A conflict request for a vehicle of each Standort
    $this->request = function (Location $location): BookingDecision {
        $vehicle = Vehicle::factory()->create();
        $vehicle->forceFill(['ext_location_id' => $location->id])->save();
        $start = now()->addDays(3)->setTime(10, 0);
        Booking::factory()->create(['vehicle_id' => $vehicle->id, 'user_id' => $this->admin->id, 'starts_at' => $start, 'ends_at' => $start->copy()->addHours(4)]);

        return app(DecisionService::class)->request([
            'vehicle_id' => $vehicle->id, 'starts_at' => $start->copy()->addHour(), 'ends_at' => $start->copy()->addHours(3), 'purpose' => 'Lehrgangsfahrt',
        ], $this->bernd, 'Der Lehrgang lässt sich nicht verschieben.');
    };
});

it('lets the Wehrführung decide about bookings of the vehicles of its Standort only', function () {
    $own = ($this->request)($this->north);
    $other = ($this->request)($this->south);

    expect(DecisionService::decidesAnywhere($this->hanna))->toBeTrue()
        ->and($this->hanna->canDecide())->toBeFalse()
        ->and(DecisionService::canDecideBooking($this->hanna, $own->booking))->toBeTrue()
        ->and(DecisionService::canDecideBooking($this->hanna, $other->booking))->toBeFalse()
        ->and(DecisionService::decidesAnywhere($this->bernd))->toBeFalse()
        // She was asked for her own Standort; for the other one nobody is responsible, so the admins were
        ->and($own->notified_user_ids)->toBe([$this->hanna->id])
        ->and($other->notified_user_ids)->toBe([$this->admin->id]);

    Livewire::actingAs($this->hanna)->test(DecisionIndex::class)
        ->assertViewHas('open', fn ($open) => $open->pluck('id')->all() === [$own->id]);
    Livewire::actingAs($this->hanna)->test(DecisionDetail::class, ['decision' => $other])->assertForbidden();
    Livewire::actingAs($this->bernd)->test(DecisionDetail::class, ['decision' => $own])->assertForbidden();

    Livewire::actingAs($this->hanna)->test(DecisionDetail::class, ['decision' => $own])
        ->set('note', 'Lehrgang geht vor.')->call('approve')->assertHasNoErrors();
    expect($own->fresh()->status)->toBe(BookingDecision::STATUS_APPROVED)
        ->and($own->fresh()->decided_by)->toBe($this->hanna->id)
        ->and($other->fresh()->status)->toBe(BookingDecision::STATUS_PENDING);
});

it('keeps deciding everywhere for admins and the classic Entscheider', function () {
    $other = ($this->request)($this->south);
    $decider = User::factory()->create();
    $decider->moduleAccess()->create(['module' => 'fahrzeugbuchung', 'role' => 'entscheider']);

    expect(DecisionService::deciderLocationIds($this->admin))->toBeNull()
        ->and(DecisionService::canDecideBooking($decider, $other->booking))->toBeTrue()
        ->and(app(DecisionService::class)->deciders($other->booking)->pluck('id')->all())->toBe([$decider->id]);
});

it('lets the Wehrführung run the Hydrantenkontrolle', function () {
    expect(InspectionService::canManage($this->hanna))->toBeTrue()
        ->and(InspectionService::canSeeAll($this->hanna))->toBeFalse()
        ->and(InspectionService::canManage($this->bernd))->toBeFalse();
});

it('lets every Wehrführung keep the records of the whole Kommune', function () {
    Module::whereIn('key', ['schluessel', 'objektplaene', 'wasserkarte', 'gefahrstoffe'])->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();

    expect(KeyService::canManage($this->hanna))->toBeTrue()
        ->and(ObjectPlanService::canManage($this->hanna))->toBeTrue()
        ->and(WaterService::canManage($this->hanna))->toBeTrue()
        ->and($this->hanna->can('gefahrstoffe-manage'))->toBeTrue()
        ->and(KeyService::canManage($this->bernd))->toBeFalse()
        ->and(ObjectPlanService::canManage($this->bernd))->toBeFalse()
        ->and(WaterService::canManage($this->bernd))->toBeFalse()
        ->and($this->bernd->can('gefahrstoffe-manage'))->toBeFalse();
});

it('lets the Wehrführung check the Führerscheine of the members of its Standort only', function () {
    Module::where('key', 'fuehrerscheine')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $sven = User::factory()->create(['name' => 'Sven Süd']);
    $sven->forceFill(['ext_location_id' => $this->south->id])->save();
    $pending = fn (User $member) => License::create(['user_id' => $member->id, 'classes' => [['class' => 'B', 'valid_until' => null]],
        'document_valid_until' => now()->addYears(5)->toDateString(), 'status' => 'pending', 'submitted_at' => now()]);
    $own = $pending($this->bernd);
    $other = $pending($sven);
    $service = app(LicenseService::class);

    expect(LicenseService::canManage($this->hanna))->toBeTrue()
        ->and(LicenseService::canManageAll($this->hanna))->toBeFalse()
        ->and(LicenseService::canManageMember($this->hanna, $this->bernd->id))->toBeTrue()
        ->and(LicenseService::canManageMember($this->hanna, $sven->id))->toBeFalse()
        ->and(LicenseService::canManageAll($this->admin))->toBeTrue()
        ->and(LicenseService::canManage($this->bernd))->toBeFalse()
        // She is asked about her Standort; where nobody is responsible the admins are
        ->and($service->managers($this->bernd)->pluck('id')->all())->toBe([$this->hanna->id])
        ->and($service->managers($sven)->pluck('id')->all())->toBe([$this->admin->id]);

    Livewire::actingAs($this->hanna)->test(Review::class)
        ->assertViewHas('pending', fn ($list) => $list->pluck('id')->all() === [$own->id])
        ->assertViewHas('canManageAll', false)
        ->call('validateLicense', $own->id)->assertHasNoErrors()
        ->call('validateLicense', $other->id)->assertForbidden();
    Livewire::actingAs($this->hanna)->test(Review::class)->call('saveInterval')->assertForbidden();

    expect($own->fresh()->status)->toBe('validated')->and($other->fresh()->status)->toBe('pending');
    expect(fn () => $service->validate($this->hanna, $other))->toThrow(ValidationException::class);
});

it('shows the Wehrführung the Lehrgänge of the members of its Standort', function () {
    Module::where('key', 'lehrgaenge')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $sven = User::factory()->create(['name' => 'Sven Süd']);
    $sven->forceFill(['ext_location_id' => $this->south->id])->save();
    $registrations = app(RegistrationService::class);
    $registrations->recordCompleted($this->bernd, 'Truppmann Teil 1', '2025-04-06');
    $registrations->recordCompleted($sven, 'Maschinist', '2025-05-10');

    expect($this->hanna->canViewLehrgaenge())->toBeTrue()
        ->and($this->hanna->canManageLehrgaenge())->toBeFalse()
        ->and(RegistrationService::canViewMember($this->hanna, $this->bernd->id))->toBeTrue()
        ->and(RegistrationService::canViewMember($this->hanna, $sven->id))->toBeFalse()
        ->and(RegistrationService::viewableMemberIds($this->admin))->toBeNull()
        ->and($this->bernd->canViewLehrgaenge())->toBeFalse();

    $table = Qualifications::table(null, RegistrationService::viewableMemberIds($this->hanna));
    expect($table['members']->pluck('name')->all())->toContain('Bernd Blaulicht')->not->toContain('Sven Süd')
        ->and($table['courses']->pluck('name')->all())->toBe(['Truppmann Teil 1']);

    $this->actingAs($this->hanna)->get(route('lehrgaenge.member', $this->bernd))->assertOk();
    $this->actingAs($this->hanna)->get(route('lehrgaenge.member', $sven))->assertForbidden();
    // The catalogue stays with the Ausbildungsleiter
    $this->actingAs($this->hanna)->get(route('lehrgaenge.courses'))->assertForbidden();
    $this->actingAs($this->hanna)->get(route('lehrgaenge.import'))->assertForbidden();
});

it('lets the Wehrführung keep registrations and absolvierte Lehrgänge of its members', function () {
    Notification::fake();
    Module::where('key', 'lehrgaenge')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $sven = User::factory()->create(['name' => 'Sven Süd']);
    $sven->forceFill(['ext_location_id' => $this->south->id])->save();
    $course = Course::create(['name' => 'Truppführer', 'is_standard' => true]);
    $registrations = app(RegistrationService::class);

    expect(RegistrationService::canManageAny($this->hanna))->toBeTrue()
        ->and($this->hanna->canManageLehrgaenge())->toBeFalse()
        ->and(RegistrationService::canManageMember($this->hanna, $this->bernd->id))->toBeTrue()
        ->and(RegistrationService::canManageMember($this->hanna, $sven->id))->toBeFalse()
        ->and(RegistrationService::manageableMemberIds($this->admin))->toBeNull()
        ->and(RegistrationService::canManageAny($this->bernd))->toBeFalse();

    // Requests reach the Wehrführung of the Kamerad's Standort
    $own = $registrations->request($this->bernd, $course, null);
    $other = $registrations->request($sven, $course, null);
    Notification::assertSentTo($this->hanna, RequestReceivedNotification::class, 1);

    Livewire::actingAs($this->hanna)->test(Registrations::class)
        ->assertViewHas('demand', fn ($demand) => $demand->flatMap(fn ($row) => $row['records'])->pluck('id')->all() === [$own->id])
        ->assertViewHas('canManageAll', false)
        ->call('waitlist', $own->id)->assertHasNoErrors()
        ->call('waitlist', $other->id)->assertForbidden();
    Livewire::actingAs($this->hanna)->test(Registrations::class)->call('import')->assertForbidden();
    expect($own->fresh()->waitlisted_at)->not->toBeNull()->and($other->fresh()->waitlisted_at)->toBeNull();

    // Absolvierte Lehrgänge: enter for own Kameraden, never for others
    $result = $registrations->recordCompletedMany($this->hanna, 'Maschinist', [$this->bernd->id], '2025-03-01');
    expect($result['added'])->toBe(['Bernd Blaulicht']);
    expect(fn () => $registrations->recordCompletedMany($this->hanna, 'Maschinist', [$this->bernd->id, $sven->id], '2025-03-01'))->toThrow(ValidationException::class)
        ->and(fn () => $registrations->registerFor($this->hanna, $course, [$sven->id]))->toThrow(ValidationException::class)
        ->and(fn () => $registrations->recordCompletedMany($this->bernd, 'Maschinist', [$this->bernd->id], '2025-03-01'))->toThrow(ValidationException::class);

    Livewire::actingAs($this->hanna)->test(MemberProfile::class, ['member' => $this->bernd])
        ->assertViewHas('canManage', true)
        ->set('entry', ['course' => 'Sprechfunker', 'completed_on' => '2024-06-01', 'note' => ''])
        ->call('addCompleted')->assertHasNoErrors();
    expect(Record::where('user_id', $this->bernd->id)->where('status', 'completed')->count())->toBe(2);
});

it('lets the Wehrführung hold Unterweisungen for its members and look after its own ones', function () {
    Module::where('key', 'unterweisungen')->update(['is_active' => true, 'auto_assign' => true]);
    app(ModuleRegistry::class)->clearCache();
    $sven = User::factory()->create(['name' => 'Sven Süd']);
    $sven->forceFill(['ext_location_id' => $this->south->id])->save();
    $topic = Topic::create(['name' => 'Jährliche Unterweisung', 'kind' => 'unterweisung', 'interval_months' => 12, 'for_all' => true]);
    $service = app(InstructionService::class);
    $data = ['topic_id' => $topic->id, 'held_on' => now('Europe/Berlin')->toDateString(), 'instructor_name' => 'Hanna Hydrant'];

    expect(InstructionService::canManage($this->hanna))->toBeTrue()
        ->and(InstructionService::isLocalManager($this->hanna))->toBeTrue()
        ->and(InstructionService::canManageAll($this->hanna))->toBeFalse()
        ->and(InstructionService::canUseTopic($this->hanna, $topic))->toBeTrue()
        ->and(InstructionService::canManageTopic($this->hanna, $topic))->toBeFalse()
        ->and(InstructionService::isLocalManager($this->admin))->toBeFalse()
        ->and(InstructionService::canManage($this->bernd))->toBeFalse();

    // Her own Kameraden yes, the other Standort no
    $own = $service->recordSession($this->hanna, $data, [$this->bernd->id, $this->hanna->id]);
    expect(fn () => $service->recordSession($this->hanna, $data, [$this->bernd->id, $sven->id]))->toThrow(ValidationException::class);
    $foreign = $service->recordSession($this->admin, $data, [$sven->id, $this->bernd->id]);

    expect(InstructionService::canManageSession($this->hanna, $own))->toBeTrue()
        ->and(InstructionService::canManageSession($this->hanna, $foreign))->toBeFalse()
        ->and(InstructionService::sessionsForManager($this->hanna)->pluck('id')->all())->toBe([$own->id])
        ->and($service->matrix(null, $this->hanna)['users']->pluck('name')->all())->toContain('Bernd Blaulicht')->not->toContain('Sven Süd')
        ->and($service->matrix(null, $this->admin)['users']->pluck('name')->all())->toContain('Sven Süd');

    // The kinds stay with the Unterweisungsbeauftragte
    expect(fn () => $service->saveTopic($this->hanna, null, ['name' => 'Eigene Art', 'kind' => 'unterweisung']))->toThrow(ValidationException::class)
        ->and(fn () => $service->addPresets($this->hanna))->toThrow(ValidationException::class);
    Livewire::actingAs($this->hanna)->test(Topics::class)->assertForbidden();
    Livewire::actingAs($this->hanna)->test(SessionDetail::class, ['session' => $foreign])->assertForbidden();
    Livewire::actingAs($this->hanna)->test(SessionDetail::class, ['session' => $own])->assertOk();
    $this->actingAs($this->hanna)->get(route('unterweisungen.overview'))->assertOk()->assertDontSee('Sven Süd');
});
