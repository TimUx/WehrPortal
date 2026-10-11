<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Notifications\Concerns\InApp;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\MailTemplate;

/** Sent an hour before a booking ends; the text is editable in the admin (MailTemplate) */
class BookingEndReminderNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Booking $booking) {}

    public function toMail(object $notifiable): MailMessage
    {
        return MailTemplate::for(MailTemplate::BOOKING_END_REMINDER)->toMailMessage([
            'name' => $notifiable->name,
            'fahrzeug' => $this->booking->vehicle->name,
            'zweck' => $this->booking->purpose,
            'beginn' => BookingSummary::dateTime($this->booking->starts_at),
            'ende' => BookingSummary::dateTime($this->booking->ends_at),
            'ende_uhrzeit' => $this->booking->ends_at->setTimezone('Europe/Berlin')->format('H:i'),
            'restzeit' => $this->timeLeft(),
        ], URL::signedRoute('fahrzeugbuchung.bookings.return', $this->booking));
    }

    /** Usually an hour; less when the booking was created or extended shortly before its end */
    private function timeLeft(): string
    {
        $minutes = (int) ceil(now()->diffInSeconds($this->booking->ends_at) / 60);

        return $minutes >= 55 ? 'einer Stunde' : $minutes.' Minuten';
    }
}
