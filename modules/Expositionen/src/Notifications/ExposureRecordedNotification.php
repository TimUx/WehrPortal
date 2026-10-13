<?php

namespace Modules\Expositionen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Expositionen\src\Models\Exposure;

/** To the Expositionsbeauftragte: a new exposure is recorded and waits for the ZED (no content in the mail) */
class ExposureRecordedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Exposure $exposure) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Neue Gefahrstoff-Exposition zur Meldung')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->exposure->person_name.' hat eine Exposition erfasst („'.$this->exposure->occasion.'“). Sie wartet auf die Meldung an die Zentrale Expositionsdatenbank (ZED) der DGUV.')
            ->action('Zur Meldeliste', route('expositionen.manage'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
