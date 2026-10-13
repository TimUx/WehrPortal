<?php

namespace Modules\Fuehrerscheine\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Fuehrerscheine\src\Models\License;

/** To the Kamerad: the Führerschein is checked – or something doesn't match */
class LicenseDecidedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly License $license) {}

    public function toMail(object $notifiable): MailMessage
    {
        $ok = $this->license->status === 'validated';
        $mail = (new MailMessage)
            ->subject($ok ? 'Führerschein geprüft' : 'Führerschein: bitte nachreichen')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($ok ? 'dein Führerschein ist geprüft. Die Fotos sind gelöscht; die nächste Prüfung ist am '.$this->license->next_check_on?->format('d.m.Y').'.'
                : 'dein Führerschein ließ sich so nicht bestätigen: „'.$this->license->rejected_reason.'“ Bitte korrigieren und neue Fotos hochladen.');

        return $mail->action('Zum Führerschein', route('fuehrerscheine.index'))->salutation(app(OrganizationSettings::class)->salutation());
    }
}
