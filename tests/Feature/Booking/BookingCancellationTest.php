<?php

use App\Models\User;
use Carbon\Carbon;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    $this->vehicle = Vehicle::factory()->create();
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->admin = User::factory()->admin()->create();
    $this->service = app(BookingService::class);

    $this->booking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-10 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Test Buchung',
        'destination' => null,
        'notes' => null,
    ], $this->owner->id);
});

test('owner can cancel their own booking', function () {
    $this->actingAs($this->owner);

    $this->assertTrue(
        $this->owner->can('cancel', $this->booking)
    );

    $this->service->cancel($this->booking);

    expect($this->booking->fresh()->isCancelled())->toBeTrue();
});

test('other user cannot cancel someone elses booking', function () {
    $this->actingAs($this->other);

    expect($this->other->can('cancel', $this->booking))->toBeFalse();
});

test('admin can cancel any booking', function () {
    $this->actingAs($this->admin);

    expect($this->admin->can('cancel', $this->booking))->toBeTrue();
});

test('cancelled booking does not show in active scope', function () {
    $this->service->cancel($this->booking);

    expect(Booking::active()->find($this->booking->id))->toBeNull();
});

test('already cancelled booking isCancelled returns true', function () {
    $this->service->cancel($this->booking);

    expect($this->booking->fresh()->isCancelled())->toBeTrue();
});

test('owner cannot edit past booking', function () {
    $this->actingAs($this->owner);

    $pastBooking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::now()->subDays(5)->utc(),
        'ends_at' => Carbon::now()->subDays(5)->addHours(4)->utc(),
        'purpose' => 'Vergangene Buchung',
        'destination' => null,
        'notes' => null,
    ], $this->owner->id);

    expect($this->owner->can('update', $pastBooking))->toBeFalse();
});

test('admin can edit past booking', function () {
    $this->actingAs($this->admin);

    $pastBooking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::now()->subDays(5)->utc(),
        'ends_at' => Carbon::now()->subDays(5)->addHours(4)->utc(),
        'purpose' => 'Vergangene Buchung',
        'destination' => null,
        'notes' => null,
    ], $this->owner->id);

    expect($this->admin->can('update', $pastBooking))->toBeTrue();
});

test('owner cannot cancel a past booking, admin can, and the form refuses the edit', function () {
    $pastBooking = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'user_id' => $this->owner->id,
        'starts_at' => Carbon::now()->subDays(2),
        'ends_at' => Carbon::now()->subDays(2)->addHours(2),
    ]);

    expect($this->owner->can('cancel', $pastBooking))->toBeFalse()
        ->and($this->admin->can('cancel', $pastBooking))->toBeTrue();

    Livewire::actingAs($this->owner)
        ->test(BookingForm::class)
        ->call('openForEdit', $pastBooking->id)
        ->assertForbidden();   // the form doesn't even load a booking that may no longer be changed

    expect($pastBooking->fresh()->purpose)->not->toBe('Nachträglich geändert');
});
