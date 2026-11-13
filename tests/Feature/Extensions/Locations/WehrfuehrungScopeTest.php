<?php

use App\Models\Module;
use App\Models\User;
use App\Services\ModuleRegistry;
use Extensions\Hydrantenkontrolle\Services\InspectionService;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\StandardRoleAssignments;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionDetail;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionIndex;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;
use Modules\Objektplaene\src\Services\ObjectPlanService;
use Modules\Schluessel\src\Services\KeyService;
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
