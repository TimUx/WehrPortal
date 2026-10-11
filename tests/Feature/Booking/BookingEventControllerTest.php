<?php

use App\Models\User;
use Carbon\Carbon;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->vehicle = Vehicle::factory()->create(['color' => '#ef4444']);
    $this->service = app(BookingService::class);
    $this->actingAs($this->user);
});

test('events endpoint returns JSON for active bookings', function () {
    $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-15 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-15 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Test Event',
        'destination' => null,
        'notes' => null,
    ], $this->user->id);

    $this->getJson(route('fahrzeugbuchung.bookings.events', [
        'start' => '2026-10-01T00:00:00',
        'end' => '2026-10-31T23:59:59',
        'vehicles' => [$this->vehicle->id],
    ]))->assertOk()
        ->assertJsonFragment(['purpose' => 'Test Event']);
});

test('cancelled bookings are not in the events feed', function () {
    $booking = $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-15 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-15 14:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Storniert',
        'destination' => null,
        'notes' => null,
    ], $this->user->id);

    $this->service->cancel($booking);

    $this->getJson(route('fahrzeugbuchung.bookings.events', [
        'start' => '2026-10-01T00:00:00',
        'end' => '2026-10-31T23:59:59',
    ]))->assertOk()
        ->assertJsonMissing(['purpose' => 'Storniert']);
});

test('vehicle filter limits results', function () {
    $otherVehicle = Vehicle::factory()->create();

    $this->service->create([
        'vehicle_id' => $this->vehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-16 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-16 12:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Vehicle A',
        'destination' => null,
        'notes' => null,
    ], $this->user->id);

    $this->service->create([
        'vehicle_id' => $otherVehicle->id,
        'group_id' => null,
        'starts_at' => Carbon::parse('2026-10-17 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-17 12:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Vehicle B',
        'destination' => null,
        'notes' => null,
    ], $this->user->id);

    $this->getJson(route('fahrzeugbuchung.bookings.events', [
        'start' => '2026-10-01T00:00:00',
        'end' => '2026-10-31T23:59:59',
        'vehicles' => [$this->vehicle->id],
    ]))->assertOk()
        ->assertJsonFragment(['purpose' => 'Vehicle A'])
        ->assertJsonMissing(['purpose' => 'Vehicle B']);
});

test('unauthenticated user cannot access events endpoint', function () {
    auth()->logout();
    // getJson sends Accept: application/json → Laravel returns 401, not redirect
    $this->getJson(route('fahrzeugbuchung.bookings.events'))
        ->assertUnauthorized();
});

test('event title shows purpose and the name of the person who booked', function () {
    $user = User::factory()->create(['name' => 'Hanna Hydrant']);
    $booking = Booking::factory()->create([
        'user_id' => $user->id,
        'purpose' => 'Übungsdienst',
        'starts_at' => Carbon::parse('2026-10-15 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-15 10:00', 'Europe/Berlin')->utc(),
    ]);

    $this->actingAs($user)
        ->getJson(route('fahrzeugbuchung.bookings.events', ['start' => '2026-10-01T00:00:00', 'end' => '2026-11-01T00:00:00']))
        ->assertJsonFragment(['title' => $booking->vehicle->displayName().' – Übungsdienst · Hanna Hydrant']);
});
