<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\BookingDecision;

/**
 * The booking that won a decision was cancelled or changed, so the one that lost is active again.
 */
class BookingReactivatedNotification extends Notification
{
    use InApp;

    public function __construct(
        private readonly BookingDecision $decision,
        private readonly Booking $reactivated,
        private readonly Booking $cause,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $own = $this->reactivated->user_id === $notifiable->id;

        $mail = (new MailMessage)
            ->subject('Buchung wieder aktiv: '.$this->reactivated->vehicle->name.' am '.BookingSummary::day($this->reactivated))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($own ? 'deine Buchung' : 'die Buchung von '.$this->reactivated->user->name)
                .' war nach einer Konfliktentscheidung storniert bzw. abgelehnt. Weil die Buchung, die damals Vorrang bekam, '
                .($this->cause->isCancelled() ? 'storniert' : 'geändert')
                .' wurde, ist das Fahrzeug wieder frei – die Buchung ist **automatisch wieder aktiv**.')
            ->line('**Wieder aktiv:** '.BookingSummary::line($this->reactivated))
            ->line('**'.($this->cause->isCancelled() ? 'Storniert' : 'Geändert').':** '.BookingSummary::line($this->cause));

        $salutation = app(OrganizationSettings::class)->salutation();

        return $notifiable->canDecide()
            ? $mail->action('Zur Entscheidung', route('fahrzeugbuchung.decisions.show', $this->decision))->salutation($salutation)
            : $mail->action('Meine Buchungen', route('fahrzeugbuchung.my-bookings'))->salutation($salutation);
    }
}
