<?php

namespace Modules\Fuehrerscheine\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the Kamerad: a class or the card runs out, or the check is due */
class LicenseReminderNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $lines */
    public function __construct(private readonly array $lines) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Führerschein: Frist')->greeting('Hallo '.$notifiable->name.',');
        foreach ($this->lines as $line) {
            $mail->line($line.'.');
        }

        return $mail->line('Ob du eine Klasse verlängerst, entscheidest du – ohne gültige Klasse darfst du die Fahrzeuge dafür aber nicht mehr fahren.')
            ->action('Zum Führerschein', route('fuehrerscheine.index'))->salutation(app(OrganizationSettings::class)->salutation());
    }
}
