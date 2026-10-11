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

function makeBooking(Vehicle $vehicle, User $user, string $start, string $end, BookingService $service): Booking
{
    $starts = Carbon::parse($start, 'Europe/Berlin')->utc();
    $ends = Carbon::parse($end, 'Europe/Berlin')->utc();

    return $service->create([
        'vehicle_id' => $vehicle->id,
        'group_id' => null,
        'starts_at' => $starts,
        'ends_at' => $ends,
        'purpose' => 'Test',
        'destination' => null,
        'notes' => null,
    ], $user->id);
}

test('overlapping bookings are rejected – start overlap', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);

    expect(fn () => makeBooking($this->vehicle, $this->user, '2026-10-01 12:00', '2026-10-01 16:00', $this->service))
        ->toThrow(ValidationException::class);
});

test('overlapping bookings are rejected – end overlap', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 10:00', '2026-10-01 16:00', $this->service);

    expect(fn () => makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 12:00', $this->service))
        ->toThrow(ValidationException::class);
});

test('overlapping bookings are rejected – contained', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 18:00', $this->service);

    expect(fn () => makeBooking($this->vehicle, $this->user, '2026-10-01 09:00', '2026-10-01 11:00', $this->service))
        ->toThrow(ValidationException::class);
});

test('overlapping bookings are rejected – containing', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 10:00', '2026-10-01 12:00', $this->service);

    expect(fn () => makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 18:00', $this->service))
        ->toThrow(ValidationException::class);
});

test('adjacent bookings are allowed – until 14:00 and from 14:00', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);

    // Should NOT throw – half-open interval [) means 14:00 end does not block 14:00 start
    $booking = makeBooking($this->vehicle, $this->user, '2026-10-01 14:00', '2026-10-01 18:00', $this->service);

    expect($booking)->toBeInstanceOf(Booking::class);
});

test('cancelled booking does not block new booking', function () {
    $first = makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);
    $this->service->cancel($first);

    // Same slot should now be available
    $second = makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);
    expect($second)->toBeInstanceOf(Booking::class);
});

test('different vehicle can book same slot', function () {
    $otherVehicle = Vehicle::factory()->create();
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);

    $booking = makeBooking($otherVehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);
    expect($booking)->toBeInstanceOf(Booking::class);
});

test('checkAvailability detects conflict', function () {
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);

    $result = $this->service->checkAvailability(
        $this->vehicle->id,
        Carbon::parse('2026-10-01 10:00', 'Europe/Berlin')->utc(),
        Carbon::parse('2026-10-01 12:00', 'Europe/Berlin')->utc(),
    );

    expect($result['available'])->toBeFalse()
        ->and($result['conflict'])->not->toBeNull();
});

test('checkAvailability returns alternative vehicles', function () {
    $altVehicle = Vehicle::factory()->create();
    makeBooking($this->vehicle, $this->user, '2026-10-01 08:00', '2026-10-01 14:00', $this->service);

    $result = $this->service->checkAvailability(
        $this->vehicle->id,
        Carbon::parse('2026-10-01 10:00', 'Europe/Berlin')->utc(),
        Carbon::parse('2026-10-01 12:00', 'Europe/Berlin')->utc(),
    );

    expect($result['alternatives'])->toHaveCount(1)
        ->and($result['alternatives']->first()->id)->toBe($altVehicle->id);
});
