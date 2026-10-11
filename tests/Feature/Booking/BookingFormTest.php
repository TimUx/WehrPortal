<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

test('booking form opens on open-booking-form event', function () {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create();

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->assertSet('show', false)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $vehicle->id)
        ->assertSet('show', true)
        ->assertSet('vehicleId', $vehicle->id)
        ->assertSet('startsAt', '2026-10-05T08:00');
});

test('booking form opens without date from the plus button', function () {
    $user = User::factory()->create();
    Vehicle::factory()->create();

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: null, vehicleId: null)
        ->assertSet('show', true)
        ->assertSee('Neue Buchung');
});

test('switching to a free vehicle checks availability without error', function () {
    $user = User::factory()->create();
    $first = Vehicle::factory()->create();
    $second = Vehicle::factory()->create();

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $first->id)
        ->set('vehicleId', $second->id)
        ->assertSet('available', true)
        ->assertSet('alternatives', []);
});

test('booking form opens in edit mode on open-edit-booking event', function () {
    $user = User::factory()->create();
    $booking = Booking::factory()->create([
        'user_id' => $user->id,
        'purpose' => 'Übungsdienst',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(2),
    ]);

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->dispatch('open-edit-booking', bookingId: $booking->id)
        ->assertSet('show', true)
        ->assertSet('bookingId', $booking->id)
        ->assertSet('purpose', 'Übungsdienst')
        ->set('purpose', 'Übungsdienst geändert')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('show', false)
        ->assertDispatched('calendar-refresh');

    expect($booking->fresh()->purpose)->toBe('Übungsdienst geändert');
});

test('admin can search for a person and book for them', function () {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Hanna Hydrant', 'email' => 'hanna@example.com']);
    User::factory()->create(['name' => 'Otto Other']);
    $vehicle = Vehicle::factory()->create();

    Livewire::actingAs($admin)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $vehicle->id)
        ->assertSet('ownerId', $admin->id)
        ->set('ownerSearch', 'hydr')
        ->assertSee('hanna@example.com')
        ->assertDontSee('Otto Other')
        ->call('selectOwner', $member->id)
        ->assertSet('ownerId', $member->id)
        ->set('purpose', 'Für Hanna')
        ->call('save')
        ->assertHasNoErrors();

    expect(Booking::where('purpose', 'Für Hanna')->sole()->user_id)->toBe($member->id);
});

test('admin can change the owner of an existing booking', function () {
    $admin = User::factory()->admin()->create();
    $newOwner = User::factory()->create();
    $booking = Booking::factory()->create(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);

    Livewire::actingAs($admin)
        ->test(BookingForm::class)
        ->dispatch('open-edit-booking', bookingId: $booking->id)
        ->assertSet('ownerId', $booking->user_id)
        ->call('selectOwner', $newOwner->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($booking->fresh()->user_id)->toBe($newOwner->id);
});

test('members cannot search people or book for someone else', function () {
    $member = User::factory()->create();
    $other = User::factory()->create(['name' => 'Otto Other']);
    $vehicle = Vehicle::factory()->create();

    Livewire::actingAs($member)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $vehicle->id)
        ->assertDontSee('Gebucht für')
        ->call('selectOwner', $other->id)
        ->assertForbidden();

    Livewire::actingAs($member)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $vehicle->id)
        ->set('ownerId', $other->id)
        ->set('purpose', 'Versuch')
        ->call('save');

    expect(Booking::where('purpose', 'Versuch')->sole()->user_id)->toBe($member->id);
});

test('moving the start moves the end and keeps the duration', function () {
    $user = User::factory()->create();
    $vehicle = Vehicle::factory()->create();

    Livewire::actingAs($user)
        ->test(BookingForm::class)
        ->dispatch('open-booking-form', date: '2026-10-05', vehicleId: $vehicle->id)
        ->assertSet('startsAt', '2026-10-05T08:00')
        ->assertSet('endsAt', '2026-10-05T11:00')
        ->set('startsAt', '2026-10-09T08:00')
        ->assertSet('endsAt', '2026-10-09T11:00')
        ->set('startsAt', '2026-10-09T20:00')
        ->assertSet('endsAt', '2026-10-09T23:00')
        ->set('endsAt', '2026-10-09T19:00')   // end before start: no duration to keep, back to the default 3 hours
        ->set('startsAt', '2026-10-12T10:00')
        ->assertSet('endsAt', '2026-10-12T13:00');
});

test('the mobile number is not shown at a booking while it is switched off', function () {
    $owner = User::factory()->create(['name' => 'Hanna Hydrant', 'phone' => '0170 1234567']);
    $booking = Booking::factory()->create(['user_id' => $owner->id]);

    $html = Blade::render('<x-fahrzeugbuchung::booking-card :booking="$booking" label="Buchung" />', ['booking' => $booking->load(['user', 'vehicle'])]);

    expect($html)->toContain('Hanna Hydrant')->not->toContain('0170 1234567')->not->toContain('tel:');
});
