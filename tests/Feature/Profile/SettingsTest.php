<?php

use App\Livewire\Profile\Settings;
use App\Models\User;
use Livewire\Livewire;

test('calendar defaults to week view', function () {
    expect(User::factory()->create()->calendarView())->toBe('timeGridWeek');
});

test('user can save preferred calendar view', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Settings::class)
        ->assertSet('calendarView', 'timeGridWeek')
        ->set('calendarView', 'dayGridMonth')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    expect($user->fresh()->calendar_view)->toBe('dayGridMonth');

    $this->actingAs($user)->get(route('fahrzeugbuchung.calendar'))
        ->assertSee('data-initial-view="dayGridMonth"', false);
});

test('invalid calendar view is rejected', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(Settings::class)
        ->set('calendarView', 'resourceTimeline')
        ->assertHasErrors('calendarView');

    expect($user->fresh()->calendar_view)->toBeNull();
});
