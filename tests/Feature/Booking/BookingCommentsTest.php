<?php

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingComments;
use Modules\Fahrzeugbuchung\src\Livewire\Booking\BookingDetail;
use Modules\Fahrzeugbuchung\src\Models\BookingComment;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->member = User::factory()->create(['name' => 'Anna Becker']);
    $this->decider = User::factory()->decider()->create();
    $this->admin = User::factory()->admin()->create();

    $this->booking = app(BookingService::class)->create([
        'vehicle_id' => Vehicle::factory()->create()->id,
        'starts_at' => Carbon::now()->addDay()->setTime(8, 0),
        'ends_at' => Carbon::now()->addDay()->setTime(14, 0),
        'purpose' => 'Zeltlager',
    ], $this->owner->id);

    $this->write = fn (User $user, string $body) => Livewire::actingAs($user)
        ->test(BookingComments::class, ['bookingId' => $this->booking->id])
        ->set('body', $body)
        ->call('send');
});

test('booking detail shows the conversation', function () {
    ($this->write)($this->member, 'Kann ich den Anhänger mitnutzen?');

    Livewire::actingAs($this->owner)
        ->test(BookingDetail::class, ['bookingId' => $this->booking->id])
        ->assertSee('Nachrichten')
        ->assertSee('Kann ich den Anhänger mitnutzen?')
        ->assertSee('Anna Becker');
});

test('any member can write on a booking', function () {
    ($this->write)($this->member, 'Kann ich den Anhänger mitnutzen?')->assertHasNoErrors();
    ($this->write)($this->owner, "Klar,\nmelde dich vorher.")->assertHasNoErrors();

    expect($this->booking->comments()->pluck('body')->all())
        ->toBe(['Kann ich den Anhänger mitnutzen?', "Klar,\nmelde dich vorher."]);
});

test('empty messages are rejected', function () {
    ($this->write)($this->member, '   ')->assertHasErrors('body');

    expect(BookingComment::count())->toBe(0);
});

test('cancelled bookings take no new messages', function () {
    app(BookingService::class)->cancel($this->booking);

    ($this->write)($this->member, 'Noch da?')->assertForbidden();
});

test('author edits a message and the old text is kept as revision', function () {
    ($this->write)($this->member, 'Abfahrt 8 Uhr');
    $comment = BookingComment::firstOrFail();

    Livewire::actingAs($this->member)
        ->test(BookingComments::class, ['bookingId' => $this->booking->id])
        ->call('startEdit', $comment->id)
        ->assertSet('editBody', 'Abfahrt 8 Uhr')
        ->set('editBody', 'Abfahrt 9 Uhr')
        ->call('saveEdit')
        ->assertSee('Abfahrt 9 Uhr')
        ->assertSee('bearbeitet');

    $comment->refresh();
    expect($comment->body)->toBe('Abfahrt 9 Uhr')
        ->and($comment->isEdited())->toBeTrue()
        ->and($comment->revisions->pluck('body')->all())->toBe(['Abfahrt 8 Uhr']);
});

test('author deletes a message softly', function () {
    ($this->write)($this->member, 'Falscher Tag, sorry');
    $comment = BookingComment::firstOrFail();

    Livewire::actingAs($this->member)
        ->test(BookingComments::class, ['bookingId' => $this->booking->id])
        ->call('delete', $comment->id)
        ->assertDontSee('Falscher Tag, sorry');

    expect(BookingComment::count())->toBe(0)
        ->and(BookingComment::withTrashed()->count())->toBe(1);
});

test('nobody can edit or delete someone elses message', function () {
    ($this->write)($this->member, 'Meine Nachricht');
    $comment = BookingComment::firstOrFail();

    foreach ([$this->owner, $this->admin] as $user) {
        Livewire::actingAs($user)
            ->test(BookingComments::class, ['bookingId' => $this->booking->id])
            ->call('startEdit', $comment->id)
            ->assertForbidden();

        Livewire::actingAs($user)
            ->test(BookingComments::class, ['bookingId' => $this->booking->id])
            ->call('delete', $comment->id)
            ->assertForbidden();
    }

    expect($comment->fresh()->trashed())->toBeFalse();
});

test('members see only that a message was edited, not deleted messages or earlier versions', function () {
    ($this->write)($this->owner, 'Erste Fassung');
    ($this->write)($this->owner, 'Wird gelöscht');
    [$edited, $deleted] = BookingComment::orderBy('id')->get();
    $edited->edit('Zweite Fassung');
    $deleted->delete();

    Livewire::actingAs($this->member)
        ->test(BookingComments::class, ['bookingId' => $this->booking->id])
        ->assertSee('Zweite Fassung')
        ->assertSee('bearbeitet')
        ->assertDontSee('Erste Fassung')
        ->assertDontSee('Frühere Fassungen')
        ->assertDontSee('Wird gelöscht');
});

test('deciders and admins see deleted messages and earlier versions', function () {
    ($this->write)($this->owner, 'Erste Fassung');
    ($this->write)($this->owner, 'Wird gelöscht');
    [$edited, $deleted] = BookingComment::orderBy('id')->get();
    $edited->edit('Zweite Fassung');
    $deleted->delete();

    foreach ([$this->decider, $this->admin] as $user) {
        Livewire::actingAs($user)
            ->test(BookingComments::class, ['bookingId' => $this->booking->id])
            ->assertSee('Zweite Fassung')
            ->assertSee('Frühere Fassungen (1)')
            ->assertSee('Erste Fassung')
            ->assertSee('Wird gelöscht')
            ->assertSee('gelöscht');
    }
});

test('a deleted message cannot be edited anymore', function () {
    ($this->write)($this->member, 'Weg damit');
    $comment = BookingComment::firstOrFail();
    $comment->delete();

    // In Livewire 4, ModelNotFoundException is handled by the exception handler
    // and returned as a 404 response rather than being re-thrown.
    Livewire::actingAs($this->member)
        ->test(BookingComments::class, ['bookingId' => $this->booking->id])
        ->call('startEdit', $comment->id)
        ->assertStatus(404);
});
