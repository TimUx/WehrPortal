<?php

namespace Modules\Fahrzeuge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeuge\src\Models\Downtime;

/** To whoever booked a vehicle that goes to the Werkstatt in that time; the booking stays */
class VehicleUnavailableNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, Booking>  $bookings */
    public function __construct(private readonly Downtime $downtime, private readonly Collection $bookings) {}

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle = $this->downtime->vehicle;
        $mail = (new MailMessage)
            ->subject($vehicle->name.' ist in deiner Buchungszeit nicht verfügbar')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($vehicle->name.' ist '.$this->downtime->periodLabel().' nicht verfügbar: '.$this->downtime->reason.'.')
            ->line('Das betrifft deine Buchung'.($this->bookings->count() === 1 ? '' : 'en').':');
        $this->bookings->each(fn (Booking $b) => $mail->line('– '.$b->starts_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' – '.$b->ends_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i').': '.$b->purpose));

        return $mail->line('Die Buchung bleibt bestehen. Bitte prüfe, ob du ein anderes Fahrzeug nimmst oder die Buchung stornierst.')
            ->action('Meine Buchungen', route('fahrzeugbuchung.my-bookings'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
