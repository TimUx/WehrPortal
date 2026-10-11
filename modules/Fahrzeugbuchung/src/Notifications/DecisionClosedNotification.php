<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

/**
 * Tells the other deciders that a decision they were asked for is no longer open.
 */
class DecisionClosedNotification extends Notification
{
    use InApp;

    public function __construct(
        private readonly BookingDecision $decision,
        private readonly bool $changed = false,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->decision->booking;
        $withdrawn = $this->decision->status === BookingDecision::STATUS_WITHDRAWN;

        $mail = (new MailMessage)
            ->subject(($this->changed ? 'Entscheidung geändert: ' : 'Erledigt: ').$request->vehicle->name.' am '.BookingSummary::day($request))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($withdrawn
                ? 'die Anfrage, zu der du um eine Entscheidung gebeten wurdest, wurde von '.$request->user->name.' **zurückgezogen**. Es ist nichts mehr zu tun.'
                : ($this->changed
                    ? 'die Entscheidung, um die du gebeten wurdest, wurde von **'.$this->decision->decider?->name.'** geändert.'
                    : 'die Entscheidung, um die du gebeten wurdest, wurde bereits von **'.$this->decision->decider?->name.'** getroffen. Es ist nichts mehr zu tun.'))
            ->line('**Anfrage:** '.BookingSummary::line($request));

        if (! $withdrawn) {
            $mail->line('**Ergebnis:** '.$this->decision->statusLabel());
            if ($this->decision->decision_note) {
                $mail->line('**Anmerkung:** '.$this->decision->decision_note);
            }
        }

        return $mail
            ->action('Details ansehen', route('fahrzeugbuchung.decisions.show', $this->decision))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
