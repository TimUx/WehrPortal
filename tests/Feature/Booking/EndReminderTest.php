<?php

use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource\Pages\EditMailTemplate;
use Modules\Fahrzeugbuchung\src\Filament\Resources\MailTemplateResource\Pages\ListMailTemplates;
use Modules\Fahrzeugbuchung\src\Models\MailTemplate;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Fahrzeugbuchung\src\Notifications\BookingEndReminderNotification;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(Carbon::parse('2026-10-10 13:05', 'Europe/Berlin'));

    $this->vehicle = Vehicle::factory()->create(['name' => 'MTW']);
    $this->owner = User::factory()->create(['name' => 'Bernd Blaulicht']);
    $this->service = app(BookingService::class);

    $this->book = fn (string $from, string $to, array $extra = []) => $this->service->create([
        'vehicle_id' => $extra['vehicle_id'] ?? $this->vehicle->id,
        'starts_at' => Carbon::parse("2026-10-10 $from", 'Europe/Berlin')->utc(),
        'ends_at' => Carbon::parse("2026-10-10 $to", 'Europe/Berlin')->utc(),
        'purpose' => $extra['purpose'] ?? 'Ausfahrt',
    ], $this->owner->id);

    // Running 08:00–14:00, so it ends within the hour
    $this->booking = ($this->book)('08:00', '14:00', ['purpose' => 'Zeltlager']);
});

test('reminder goes out once to the booker when the booking ends within the hour', function () {
    Notification::fake();

    $this->artisan('bookings:send-end-reminders')->assertSuccessful();
    $this->artisan('bookings:send-end-reminders')->assertSuccessful();

    Notification::assertSentToTimes($this->owner, BookingEndReminderNotification::class, 1);
    expect($this->booking->fresh()->end_reminder_sent_at)->not->toBeNull();
});

test('no reminder for bookings ending later, cancelled or lasting an hour or less', function () {
    Notification::fake();
    $this->service->cancel($this->booking);

    $other = Vehicle::factory()->create();
    ($this->book)('08:00', '15:00', ['vehicle_id' => $other->id]);      // ends in almost 2 hours
    ($this->book)('13:00', '14:00', ['vehicle_id' => Vehicle::factory()->create()->id]); // only 1 hour long

    $this->artisan('bookings:send-end-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

test('a changed end time gets a new reminder', function () {
    Notification::fake();
    $this->artisan('bookings:send-end-reminders');

    $this->service->update($this->booking, [
        'ends_at' => Carbon::parse('2026-10-10 14:30', 'Europe/Berlin')->utc(),
    ]);
    expect($this->booking->fresh()->end_reminder_sent_at)->toBeNull();

    $this->artisan('bookings:send-end-reminders'); // 14:30 is not within the hour yet
    Notification::assertSentToTimes($this->owner, BookingEndReminderNotification::class, 1);

    $this->travelTo(Carbon::parse('2026-10-10 13:31', 'Europe/Berlin'));
    $this->artisan('bookings:send-end-reminders');

    Notification::assertSentToTimes($this->owner, BookingEndReminderNotification::class, 2);
});

test('reminder mail uses the default text with booking details', function () {
    $this->travelTo(Carbon::parse('2026-10-10 13:15', 'Europe/Berlin'));

    $mail = (new BookingEndReminderNotification($this->booking))->toMail($this->owner);

    expect($mail->subject)->toBe('Erinnerung: Deine Buchung von MTW endet um 14:00 Uhr')
        ->and($mail->greeting)->toBe('Hallo Bernd Blaulicht,')
        ->and($mail->introLines[0])->toContain('**MTW** („Zeltlager“) endet in 45 Minuten, um **14:00 Uhr**')
        ->and($mail->actionText)->toBe('Fahrzeug ist schon zurück')
        ->and($mail->actionUrl)->toContain('/fahrzeugbuchung/buchungen/'.$this->booking->id.'/zurueckgeben?signature=');
});

test('reminder says one hour when sent on time', function () {
    $this->travelTo(Carbon::parse('2026-10-10 13:00:30', 'Europe/Berlin'));

    $mail = (new BookingEndReminderNotification($this->booking))->toMail($this->owner);

    expect($mail->introLines[0])->toContain('endet in einer Stunde, um **14:00 Uhr**');
});

test('reminder mail uses the text edited by an admin', function () {
    MailTemplate::for(MailTemplate::BOOKING_END_REMINDER)->update([
        'subject' => '{fahrzeug} bitte bis {ende_uhrzeit} zurück',
        'body' => "Erster Absatz für {name}.\n\nZweiter Absatz.",
        'action_label' => null,
    ]);

    $mail = (new BookingEndReminderNotification($this->booking))->toMail($this->owner);

    expect($mail->subject)->toBe('MTW bitte bis 14:00 zurück')
        ->and($mail->introLines)->toBe(['Erster Absatz für Bernd Blaulicht.', 'Zweiter Absatz.'])
        ->and($mail->actionText)->toBeNull();
});

test('signed link returns the vehicle after confirming, without login', function () {
    $url = URL::signedRoute('fahrzeugbuchung.bookings.return', $this->booking);

    // Opening the link (e.g. by a mail scanner) changes nothing
    $this->get($url)->assertOk()->assertSee('Fahrzeug ist zurück');
    expect($this->booking->fresh()->isRunning())->toBeTrue();

    $this->post($url)->assertRedirect($url);
    expect($this->booking->fresh()->isRunning())->toBeFalse()
        ->and($this->booking->fresh()->isCancelled())->toBeFalse();

    $this->get($url)->assertSee('Danke!');
    $this->get($url)->assertSee('Buchung bereits beendet');
});

test('return link without valid signature is rejected', function () {
    $this->get('/fahrzeugbuchung/buchungen/'.$this->booking->id.'/zurueckgeben')->assertForbidden();
    $this->post('/fahrzeugbuchung/buchungen/'.$this->booking->id.'/zurueckgeben')->assertForbidden();

    expect($this->booking->fresh()->isRunning())->toBeTrue();
});

test('admin sees and edits the mail texts', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(ListMailTemplates::class)
        ->assertSee('Erinnerung: Buchung endet bald');

    $template = MailTemplate::where('key', MailTemplate::BOOKING_END_REMINDER)->firstOrFail();

    Livewire::test(EditMailTemplate::class, ['record' => $template->getRouteKey()])
        ->fillForm(['subject' => 'Neuer Betreff'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($template->fresh()->subject)->toBe('Neuer Betreff');

    Livewire::test(EditMailTemplate::class, ['record' => $template->getRouteKey()])
        ->callAction('reset');
    expect($template->fresh()->subject)->toBe(MailTemplate::defaults()[MailTemplate::BOOKING_END_REMINDER]['subject']);
});

test('members cannot open the mail texts', function () {
    $this->actingAs($this->owner);

    $this->get('/admin/fahrzeugbuchung/e-mail-texte')->assertForbidden();
});
