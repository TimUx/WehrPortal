<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

/**
 * Sent to the requester and to the owners of the conflicting bookings.
 */
class DecisionMadeNotification extends Notification
{
    use InApp;

    /** @param bool $changed an earlier decision was reversed */
    public function __construct(
        private readonly BookingDecision $decision,
        private readonly bool $changed = false,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->decision->booking;
        $approved = $this->decision->status === BookingDecision::STATUS_APPROVED;
        $isRequester = $request->user_id === $notifiable->id;

        $mail = (new MailMessage)
            ->subject(($this->changed ? 'Entscheidung geändert: ' : 'Entscheidung zu ').$request->vehicle->name.' am '.BookingSummary::day($request))
            ->greeting('Hallo '.$notifiable->name.',');

        if ($this->changed) {
            $mail->line('eine bereits getroffene Entscheidung wurde **geändert**. Es gilt ab sofort:');
        }

        if ($isRequester && $this->decision->replacedBooking) {
            $mail->line($approved
                ? 'deine Änderung wurde **genehmigt**. Deine Buchung gilt jetzt mit den neuen Angaben.'
                : 'deine Änderung wurde **abgelehnt**. Deine bisherige Buchung bleibt unverändert bestehen.');
            $mail->line('**Angefragte Änderung:** '.BookingSummary::line($request));
        } elseif ($isRequester) {
            $mail->line($approved
                ? 'deine Anfrage wurde **genehmigt**. Das Fahrzeug ist für dich gebucht.'
                : 'deine Anfrage wurde **abgelehnt**. Die bestehende Buchung bleibt bestehen.');
            $mail->line('**Deine Anfrage:** '.BookingSummary::line($request));
        } else {
            $mail->line($approved
                ? 'zu deiner Buchung gab es eine Konfliktanfrage. Es wurde entschieden, dass die Anfrage Vorrang hat – deine Buchung wurde daher **storniert**.'
                : 'zu deiner Buchung gab es eine Konfliktanfrage. Es wurde entschieden, dass deine Buchung **bestehen bleibt**.');
            $mail->line('**Anfrage von '.$request->user->name.':** '.BookingSummary::line($request));
        }

        $mail->line('**Entschieden von:** '.$this->decision->decider?->name.', '.BookingSummary::dateTime($this->decision->decided_at));

        if ($this->decision->decision_note) {
            $mail->line('**Anmerkung:** '.$this->decision->decision_note);
        }

        return $mail
            ->action('Meine Buchungen', route('fahrzeugbuchung.my-bookings'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
