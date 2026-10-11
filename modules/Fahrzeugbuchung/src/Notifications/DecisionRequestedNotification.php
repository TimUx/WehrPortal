<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

class DecisionRequestedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly BookingDecision $decision) {}

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->decision->booking;

        $mail = (new MailMessage)
            ->subject('Entscheidung nötig: '.$request->vehicle->name.' am '.BookingSummary::day($request))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('für ein Fahrzeug liegt ein Buchungskonflikt vor. Bitte entscheide, wer das Fahrzeug bekommt.')
            ->line('**'.($this->decision->replacedBooking ? 'Änderungsanfrage' : 'Anfrage').':** '.BookingSummary::line($request))
            ->line('**Begründung:** '.$this->decision->reason);

        if ($this->decision->replacedBooking) {
            $mail->line('**Bisherige Buchung (wird bei Genehmigung ersetzt):** '.BookingSummary::line($this->decision->replacedBooking));
        }

        foreach ($this->decision->conflictingBookings() as $existing) {
            $mail->line('**Bestehende Buchung:** '.BookingSummary::line($existing));
        }

        return $mail
            ->action('Zur Entscheidung', route('fahrzeugbuchung.decisions.show', $this->decision))
            ->line('Sobald jemand entschieden hat, werden alle Beteiligten per E-Mail informiert.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
