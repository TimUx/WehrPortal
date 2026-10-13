<?php

namespace Modules\Fuehrerscheine\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fuehrerscheine\src\Models\License;

/** To the Führerscheinprüfer: a Führerschein waits for the check */
class LicenseSubmittedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly License $license) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Führerschein zur Prüfung: '.$this->license->user->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->license->user->name.' hat Fotos des Führerscheins hochgeladen. Bitte mit den Angaben abgleichen und bestätigen.')
            ->action('Führerscheine prüfen', route('fuehrerscheine.review'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
