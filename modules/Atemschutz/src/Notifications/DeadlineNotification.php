<?php

namespace Modules\Atemschutz\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To an Atemschutzgeräteträger: a deadline (G26.3, Belastungsübung, Unterweisung) runs out */
class DeadlineNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $lines */
    public function __construct(private readonly array $lines) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Atemschutz: Frist läuft ab')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Für deine Einsatzbereitschaft als Atemschutzgeräteträger steht etwas an:');
        foreach ($this->lines as $line) {
            $mail->line('– '.$line);
        }

        return $mail->line('Ohne gültige Nachweise darfst du nicht unter Atemschutz eingesetzt werden. Bitte sprich den Atemschutzwart an.')
            ->action('Meinen Atemschutz-Status ansehen', route('atemschutz.personnel-file'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
