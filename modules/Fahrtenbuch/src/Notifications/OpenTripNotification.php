<?php

namespace Modules\Fahrtenbuch\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fahrtenbuch\src\Models\Trip;

/** To the driver: a trip has been open for hours; it blocks the next one of this vehicle */
class OpenTripNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Trip $trip) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Fahrtenbuch: Fahrt mit '.$this->trip->vehicle->name.' noch offen')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('deine Fahrt mit '.$this->trip->vehicle->name.' seit '.$this->trip->started_at->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr ist noch nicht beendet.')
            ->line('Bitte trag Kilometerstand und Ziel ein – solange die Fahrt offen ist, kann niemand eine neue mit dem Fahrzeug beginnen.')
            ->action('Fahrt beenden', route('fahrtenbuch.trip', ['fahrt' => $this->trip->id]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
