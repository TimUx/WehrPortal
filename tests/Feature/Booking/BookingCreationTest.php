<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    $this->vehicle = Vehicle::factory()->create();
    $this->user = User::factory()->create();
    $this->service = app(BookingService::class);
});

test('authenticated user can create a booking', function () {
    $this->actingAs($this->user);

    $booking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-05 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-05 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Jugendfeuerwehr Übung',
        'destination' => 'Feuerwehrhaus Musterstadt',
        'notes' => null,
    ], $this->user->id);

    expect($booking)->toBeInstanceOf(Booking::class)
        ->and($booking->purpose)->toBe('Jugendfeuerwehr Übung')
        ->and($booking->cancelled_at)->toBeNull();
});

test('ends_at must be after starts_at', function () {
    expect(fn () => $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-05 14:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-05 08:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Test',
        'destination' => null,
        'notes' => null,
    ], $this->user->id))->toThrow(ValidationException::class);
});

test('inactive vehicle cannot be booked via active scope', function () {
    $inactive = Vehicle::factory()->inactive()->create();

    // Inactive vehicles should not appear in active() scope
    expect(Vehicle::active()->pluck('id'))->not->toContain($inactive->id);
});

test('booking belongs to correct user', function () {
    $booking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-06 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-06 12:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Test',
        'destination' => null,
        'notes' => null,
    ], $this->user->id);

    expect($booking->user_id)->toBe($this->user->id);
});
