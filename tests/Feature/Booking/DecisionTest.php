<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingForm;
use Modules\Fahrzeugbuchung\src\Livewire\Decisions\DecisionDetail;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Notifications\BookingReactivatedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionClosedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionMadeNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionReopenedNotification;
use Modules\Fahrzeugbuchung\src\Notifications\DecisionRequestedNotification;
use Modules\Fahrzeugbuchung\src\Services\BookingService;
use Modules\Fahrzeugbuchung\src\Services\DecisionService;

beforeEach(function () {
    Notification::fake();

    $this->vehicle = Vehicle::factory()->create();
    $this->owner = User::factory()->create();
    $this->requester = User::factory()->create();
    $this->deciderA = User::factory()->decider()->create();
    $this->deciderB = User::factory()->decider()->create();

    $this->existing = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'user_id' => $this->owner->id,
        'starts_at' => Carbon::parse('2026-10-10 10:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 14:00', 'Europe/Berlin')->utc(),
    ]);
});

function requestConflict(): BookingDecision
{
    return app(DecisionService::class)->request([
        'vehicle_id' => test()->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-10 12:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 16:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Übung Nachbarwehr',
    ], test()->requester, 'Nur mit diesem Fahrzeug möglich');
}

test('booking form sends a conflict request instead of failing', function () {
    Livewire::actingAs($this->requester)
        ->test(BookingForm::class)
        ->call('open', '2026-10-10', $this->vehicle->id)
        ->set('startsAt', '2026-10-10T12:00')
        ->set('endsAt', '2026-10-10T16:00')
        ->assertSet('available', false)
        ->set('purpose', 'Übung')
        ->call('save')
        ->assertHasErrors('reason')
        ->set('reason', 'Wichtig')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('fahrzeugbuchung.calendar'));

    $request = Booking::where('user_id', $this->requester->id)->sole();
    expect($request->status)->toBe(Booking::STATUS_PENDING)
        ->and($request->decision->conflicting_booking_ids)->toBe([$this->existing->id]);
});

test('conflict request notifies all deciders with a link', function () {
    $decision = requestConflict();

    Notification::assertSentTo([$this->deciderA, $this->deciderB], DecisionRequestedNotification::class,
        function ($notification, $channels, $notifiable) use ($decision) {
            return $notification->toMail($notifiable)->actionUrl === route('fahrzeugbuchung.decisions.show', $decision);
        });
    Notification::assertNotSentTo([$this->owner, $this->requester], DecisionRequestedNotification::class);
});

test('admins are asked when there is no decider', function () {
    DB::table('module_user')
        ->where('module', 'fahrzeugbuchung')
        ->where('role', 'entscheider')
        ->delete();
    $admin = User::factory()->admin()->create();

    requestConflict();

    Notification::assertSentTo($admin, DecisionRequestedNotification::class);
});

test('pending request does not block the vehicle and existing booking stays', function () {
    requestConflict();

    expect($this->existing->fresh()->isCancelled())->toBeFalse()
        ->and(Booking::active()->count())->toBe(1);
});

test('approving confirms the request, cancels the existing booking and informs everyone', function () {
    $decision = requestConflict();

    Livewire::actingAs($this->deciderA)
        ->test(DecisionDetail::class, ['decision' => $decision])
        ->set('note', 'Übung hat Vorrang')
        ->call('approve')
        ->assertHasNoErrors();

    $decision->refresh();
    expect($decision->status)->toBe(BookingDecision::STATUS_APPROVED)
        ->and($decision->decided_by)->toBe($this->deciderA->id)
        ->and($decision->decision_note)->toBe('Übung hat Vorrang')
        ->and($decision->booking->status)->toBe(Booking::STATUS_CONFIRMED)
        ->and($this->existing->fresh()->isCancelled())->toBeTrue();

    Notification::assertSentTo([$this->requester, $this->owner], DecisionMadeNotification::class);
    Notification::assertSentTo($this->deciderB, DecisionClosedNotification::class);
    Notification::assertNotSentTo($this->deciderA, DecisionClosedNotification::class);
});

test('rejecting keeps the existing booking', function () {
    $decision = requestConflict();

    app(DecisionService::class)->decide($decision, $this->deciderB, approve: false);

    expect($decision->fresh()->status)->toBe(BookingDecision::STATUS_REJECTED)
        ->and($decision->booking->fresh()->status)->toBe(Booking::STATUS_REJECTED)
        ->and($this->existing->fresh()->isCancelled())->toBeFalse();

    Notification::assertSentTo([$this->requester, $this->owner], DecisionMadeNotification::class);
    Notification::assertSentTo($this->deciderA, DecisionClosedNotification::class);
});

test('a second decider cannot decide again', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: false);

    Livewire::actingAs($this->deciderB)
        ->test(DecisionDetail::class, ['decision' => $decision->fresh()])
        ->call('approve')
        ->assertHasErrors('decision');

    expect($decision->fresh()->status)->toBe(BookingDecision::STATUS_REJECTED)
        ->and($this->existing->fresh()->isCancelled())->toBeFalse();
});

test('cancelling a pending request withdraws the decision', function () {
    $decision = requestConflict();

    app(BookingService::class)->cancel($decision->booking);

    expect($decision->fresh()->status)->toBe(BookingDecision::STATUS_WITHDRAWN);
    Notification::assertSentTo([$this->deciderA, $this->deciderB], DecisionClosedNotification::class);
});

test('only deciders and admins can open decisions', function () {
    $decision = requestConflict();

    $this->actingAs($this->requester)->get(route('fahrzeugbuchung.decisions.show', $decision))->assertForbidden();
    $this->actingAs($this->deciderA)->get(route('fahrzeugbuchung.decisions.show', $decision))->assertOk()->assertSee('Anfrage genehmigen');
    $this->actingAs(User::factory()->admin()->create())->get(route('fahrzeugbuchung.decisions.index'))->assertOk();
});

test('deciders see the red decision button while a decision is open', function () {
    $this->actingAs($this->deciderA)->get(route('fahrzeugbuchung.calendar'))->assertDontSee('Entscheidung (');

    requestConflict();

    $this->actingAs($this->deciderA)->get(route('fahrzeugbuchung.calendar'))->assertSee('Entscheidung (1)');
    $this->actingAs($this->requester)->get(route('fahrzeugbuchung.calendar'))->assertDontSee('Entscheidung (');
});

test('pending requests show up in the calendar feed', function () {
    requestConflict();

    $this->actingAs($this->requester)
        ->getJson(route('fahrzeugbuchung.bookings.events', ['start' => '2026-10-01T00:00:00', 'end' => '2026-11-01T00:00:00']))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonFragment(['classNames' => ['fc-event-pending']]);
});

test('editing a booking into a conflict sends a change request and keeps the original', function () {
    $mine = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'user_id' => $this->requester->id,
        'starts_at' => Carbon::parse('2026-10-10 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 09:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Original',
    ]);

    Livewire::actingAs($this->requester)
        ->test(BookingForm::class)
        ->call('openForEdit', $mine->id)
        ->set('startsAt', '2026-10-10T12:00')
        ->set('endsAt', '2026-10-10T13:00')
        ->assertSet('available', false)
        ->set('reason', 'Termin verschoben')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('fahrzeugbuchung.calendar'));

    $decision = BookingDecision::sole();
    expect($decision->replaces_booking_id)->toBe($mine->id)
        ->and($decision->conflicting_booking_ids)->toBe([$this->existing->id])
        ->and($mine->fresh()->isCancelled())->toBeFalse()
        ->and($mine->fresh()->starts_at->equalTo(Carbon::parse('2026-10-10 08:00', 'Europe/Berlin')))->toBeTrue();
    Notification::assertSentTo($this->deciderA, DecisionRequestedNotification::class);

    return $decision;
});

test('approving a change request replaces the original booking', function () {
    $mine = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'user_id' => $this->requester->id,
        'starts_at' => Carbon::parse('2026-10-10 15:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 16:00', 'Europe/Berlin')->utc(),
    ]);

    $decision = app(DecisionService::class)->request([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-10 13:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 15:30', 'Europe/Berlin')->utc(),
        'purpose' => 'Verlängert',
    ], $this->requester, 'Länger nötig', $mine);

    // The original itself overlaps the requested slot but must not count as a conflict
    expect($decision->conflicting_booking_ids)->toBe([$this->existing->id]);

    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);

    expect($mine->fresh()->isCancelled())->toBeTrue()
        ->and($this->existing->fresh()->isCancelled())->toBeTrue()
        ->and($decision->booking->fresh()->status)->toBe(Booking::STATUS_CONFIRMED);
});

test('rejecting a change request keeps the original booking', function () {
    $mine = Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'user_id' => $this->requester->id,
        'starts_at' => Carbon::parse('2026-10-10 08:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 09:00', 'Europe/Berlin')->utc(),
    ]);

    $decision = app(DecisionService::class)->request([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-10 12:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 13:00', 'Europe/Berlin')->utc(),
        'purpose' => 'Verschoben',
    ], $this->requester, 'Bitte', $mine);

    app(DecisionService::class)->decide($decision, $this->deciderA, approve: false);

    expect($mine->fresh()->isCancelled())->toBeFalse()
        ->and($this->existing->fresh()->isCancelled())->toBeFalse()
        ->and($decision->booking->fresh()->status)->toBe(Booking::STATUS_REJECTED);
});

test('an approved decision can be changed to keep the existing booking', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);

    Livewire::actingAs($this->deciderB)
        ->test(DecisionDetail::class, ['decision' => $decision->fresh()])
        ->set('note', 'Doch lieber der Ausflug')
        ->call('change')
        ->assertHasNoErrors();

    $decision->refresh();
    expect($decision->status)->toBe(BookingDecision::STATUS_REJECTED)
        ->and($decision->decided_by)->toBe($this->deciderB->id)
        ->and($decision->booking->status)->toBe(Booking::STATUS_REJECTED)
        ->and($this->existing->fresh()->isCancelled())->toBeFalse()
        ->and(collect($decision->history)->pluck('action')->all())->toBe(['requested', 'approved', 'changed_rejected']);

    Notification::assertSentTo([$this->requester, $this->owner], DecisionMadeNotification::class,
        fn ($n, $c, $notifiable) => str_starts_with($n->toMail($notifiable)->subject, 'Entscheidung geändert'));
});

test('a rejected decision can be changed to approve the request', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: false);

    app(DecisionService::class)->change($decision, $this->deciderA);

    expect($decision->fresh()->status)->toBe(BookingDecision::STATUS_APPROVED)
        ->and($decision->booking->fresh()->status)->toBe(Booking::STATUS_CONFIRMED)
        ->and($this->existing->fresh()->isCancelled())->toBeTrue();
});

test('reopening restores the state before the decision and informs everyone', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);

    Livewire::actingAs($this->deciderA)
        ->test(DecisionDetail::class, ['decision' => $decision->fresh()])
        ->call('reopen')
        ->assertHasNoErrors();

    $decision->refresh();
    expect($decision->status)->toBe(BookingDecision::STATUS_PENDING)
        ->and($decision->decided_by)->toBeNull()
        ->and($decision->booking->status)->toBe(Booking::STATUS_PENDING)
        ->and($this->existing->fresh()->isCancelled())->toBeFalse();

    Notification::assertSentTo(
        [$this->requester, $this->owner, $this->deciderA, $this->deciderB],
        DecisionReopenedNotification::class
    );

    $this->actingAs($this->deciderB)->get(route('fahrzeugbuchung.calendar'))->assertSee('Entscheidung (1)');
});

test('reversal fails with a message when the freed slot was booked in the meantime', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);

    // Request shrinks, someone else books the freed morning
    $decision->booking->update(['starts_at' => Carbon::parse('2026-10-10 13:00', 'Europe/Berlin')->utc()]);
    Booking::factory()->create([
        'vehicle_id' => $this->vehicle->id,
        'starts_at' => Carbon::parse('2026-10-10 10:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-10 12:00', 'Europe/Berlin')->utc(),
    ]);

    Livewire::actingAs($this->deciderA)
        ->test(DecisionDetail::class, ['decision' => $decision->fresh()])
        ->call('reopen')
        ->assertHasErrors('decision');

    expect($decision->fresh()->status)->toBe(BookingDecision::STATUS_APPROVED)
        ->and($this->existing->fresh()->isCancelled())->toBeTrue();
});

test('cancelling an approved request reactivates the booking that lost', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);
    Notification::fake();

    app(BookingService::class)->cancel($decision->booking->fresh());

    expect($this->existing->fresh()->isCancelled())->toBeFalse()
        ->and(collect($decision->fresh()->history)->last()['action'])->toBe('reactivated');
    Notification::assertSentTo(
        [$this->owner, $this->requester, $this->deciderA, $this->deciderB],
        BookingReactivatedNotification::class
    );
});

test('moving an approved request away reactivates the booking that lost', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);

    app(BookingService::class)->update($decision->booking->fresh(), [
        'starts_at' => Carbon::parse('2026-10-11 12:00', 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse('2026-10-11 16:00', 'Europe/Berlin')->utc(),
    ]);

    expect($this->existing->fresh()->isCancelled())->toBeFalse();
});

test('a change that still overlaps does not reactivate anything', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: true);
    Notification::fake();

    app(BookingService::class)->update($decision->booking->fresh(), ['purpose' => 'Nur Text geändert']);

    expect($this->existing->fresh()->isCancelled())->toBeTrue();
    Notification::assertNothingSent();
});

test('cancelling the kept booking confirms the rejected request', function () {
    $decision = requestConflict();
    app(DecisionService::class)->decide($decision, $this->deciderA, approve: false);

    app(BookingService::class)->cancel($this->existing->fresh());

    expect($decision->booking->fresh()->status)->toBe(Booking::STATUS_CONFIRMED);
    Notification::assertSentTo($this->requester, BookingReactivatedNotification::class);
});

test('mobile tab bar shows decisions for deciders; Profil and Admin are in the 3×3 menu', function () {
    $this->actingAs($this->requester)->get(route('fahrzeugbuchung.calendar'))
        ->assertDontSee('Entscheid.')
        ->assertSee('grid-template-columns: repeat(2', false);

    $this->actingAs($this->deciderA)->get(route('fahrzeugbuchung.calendar'))
        ->assertSee('Entscheid.')
        ->assertSee('grid-template-columns: repeat(3', false);

    $this->actingAs(User::factory()->admin()->create())->get(route('fahrzeugbuchung.calendar'))
        ->assertSee('Entscheid.')
        ->assertSee('grid-template-columns: repeat(3', false)
        ->assertSee('data-launcher-admin', false);
});
