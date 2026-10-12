<?php

namespace Modules\Fahrzeugbuchung\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrzeugbuchung\src\Models\Booking;

/** An admin or Entscheider booked, changed, cancelled or ended the Kamerad's booking */
class BookingChangedByOtherNotification extends Notification
{
    use InApp;

    public const ACTIONS = [
        'created' => ['Für dich gebucht', 'für dich **gebucht**'],
        'updated' => ['Buchung geändert', '**geändert**'],
        'cancelled' => ['Buchung storniert', '**storniert**'],
        'ended' => ['Buchung beendet', 'als zurückgegeben **beendet**'],
    ];

    public function __construct(
        private readonly Booking $booking,
        private readonly string $action,
        private readonly User $by,
        private readonly ?string $before = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $verb] = self::ACTIONS[$this->action];
        $booking = $this->booking;

        $mail = (new MailMessage)
            ->subject($subject.': '.$booking->vehicle->name.' am '.BookingSummary::day($booking))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by->name.' hat '.($this->action === 'created' ? 'das Fahrzeug **'.$booking->vehicle->name.'** ' : 'deine Buchung von **'.$booking->vehicle->name.'** ').$verb.'.')
            ->line(($this->action === 'cancelled' ? '**Stornierte Buchung:** ' : '**Buchung:** ').BookingSummary::line($booking));

        if ($this->before && $this->action === 'updated') {
            $mail->line('**Bisher:** '.$this->before);
        }

        return $mail
            ->action('Meine Buchungen', route('fahrzeugbuchung.my-bookings'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
