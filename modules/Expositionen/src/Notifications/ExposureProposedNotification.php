<?php

namespace Modules\Expositionen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Expositionen\src\Models\Exposure;

/** To the Kamerad: an exposure was proposed for an Einsatz – please confirm or correct (no content in the mail) */
class ExposureProposedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Exposure $exposure) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Bitte Gefahrstoff-Exposition bestätigen')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('für „'.$this->exposure->occasion.'“ wurde eine Gefahrstoff-Exposition für dich eingetragen. Bitte prüfe die Angaben, ergänze sie und bestätige sie – sie wird 40 Jahre aufbewahrt und dient deinem Schutz.')
            ->action('Exposition prüfen', route('expositionen.edit', $this->exposure))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
