<?php

use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Filament\Resources\BookingResource\Pages\ListBookings;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingDetail;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\MyBookings;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(Carbon::parse('2026-10-10 10:30:20', 'Europe/Berlin'));

    $this->vehicle = Vehicle::factory()->create();
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->service = app(BookingService::class);

    // Running: started 08:00, planned until 14:00
    $this->booking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-10 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Ausfahrt',
    ], $this->owner->id);
});

test('owner and admin can end a running booking early, others cannot', function () {
    expect($this->owner->can('endEarly', $this->booking))->toBeTrue()
        ->and($this->admin->can('endEarly', $this->booking))->toBeTrue()
        ->and($this->other->can('endEarly', $this->booking))->toBeFalse();
});

test('deciders can return someone elses vehicle from the booking detail', function () {
    $decider = User::factory()->decider()->create();
    $this->actingAs($decider);

    expect($decider->can('endEarly', $this->booking))->toBeTrue()
        ->and($decider->can('update', $this->booking))->toBeFalse()
        ->and($decider->can('cancel', $this->booking))->toBeFalse();

    Livewire::test(BookingDetail::class, ['bookingId' => $this->booking->id])
        ->assertSee('Fahrzeug zurückgeben')
        ->call('endEarly');

    expect($this->booking->fresh()->isRunning())->toBeFalse();
});

test('admin returns a vehicle in the admin booking list', function () {
    $this->actingAs($this->admin);

    Livewire::test(ListBookings::class)
        ->assertTableActionVisible('endEarly', $this->booking)
        ->callTableAction('endEarly', $this->booking);

    $booking = $this->booking->fresh();
    expect($booking->isRunning())->toBeFalse()
        ->and($booking->isCancelled())->toBeFalse()
        ->and($booking->ends_at->equalTo(Carbon::parse('2026-10-10 10:30', 'Europe/Berlin')))->toBeTrue();
});

test('admin list offers return only for running bookings', function () {
    $this->actingAs($this->admin);
    $future = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-11 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-11 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Morgen',
    ], $this->owner->id);

    Livewire::test(ListBookings::class)
        ->assertTableActionHidden('endEarly', $future);
});

test('running bookings still cannot be edited or cancelled by members', function () {
    expect($this->owner->can('update', $this->booking))->toBeFalse()
        ->and($this->owner->can('cancel', $this->booking))->toBeFalse();
});

test('only running bookings can be ended early', function () {
    $future = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-11 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-11 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Morgen',
    ], $this->owner->id);
    $past = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-09 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-09 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Gestern',
    ], $this->owner->id);

    expect($this->owner->can('endEarly', $future))->toBeFalse()
        ->and($this->owner->can('endEarly', $past))->toBeFalse();
});

test('ending early sets the end to the current minute and frees the vehicle', function () {
    $this->service->endEarly($this->booking);

    $booking = $this->booking->fresh();
    expect($booking->ends_at->equalTo(Carbon::parse('2026-10-10 10:30', 'Europe/Berlin')))->toBeTrue()
        ->and($booking->isCancelled())->toBeFalse();

    $availability = $this->service->checkAvailability(
        $this->vehicle->id,
        Carbon::parse('2026-10-10 10:30', 'Europe/Berlin')->utc(),
        Carbon::parse('2026-10-10 14:00', 'Europe/Berlin')->utc(),
    );
    expect($availability['available'])->toBeTrue();
});

test('owner returns the vehicle from the booking detail', function () {
    $this->actingAs($this->owner);

    Livewire::test(BookingDetail::class, ['bookingId' => $this->booking->id])
        ->assertSee('Fahrzeug zurückgeben')
        ->call('endEarly')
        ->assertDispatched('calendar-refresh');

    expect($this->booking->fresh()->ends_at->isPast())->toBeTrue();
});

test('owner returns the vehicle from my bookings', function () {
    $this->actingAs($this->owner);

    Livewire::test(MyBookings::class)
        ->assertSee('Zurückgeben')
        ->call('endEarly', $this->booking->id);

    expect($this->booking->fresh()->ends_at->isPast())->toBeTrue();
});

test('other user cannot return someone elses vehicle', function () {
    $this->actingAs($this->other);

    Livewire::test(BookingDetail::class, ['bookingId' => $this->booking->id])
        ->assertDontSee('Fahrzeug zurückgeben')
        ->call('endEarly')
        ->assertForbidden();

    expect($this->booking->fresh()->ends_at->equalTo(Carbon::parse('2026-10-10 14:00', 'Europe/Berlin')))->toBeTrue();
});
