<?php

namespace Modules\Atemschutz\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** To the Atemschutzwarte: which Geräteträger have deadlines running out */
class PoolDigestNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, array{name: string, lines: Collection<int, string>}>  $entries */
    public function __construct(private readonly Collection $entries) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Atemschutz: Fristen von '.$this->entries->count().' Geräteträger'.($this->entries->count() === 1 ? '' : 'n'))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Bei diesen Atemschutzgeräteträgern laufen Nachweise ab oder sind abgelaufen:');
        foreach ($this->entries as $entry) {
            $mail->line('– '.$entry['name'].': '.collect($entry['lines'])->implode(', '));
        }

        return $mail->action('Geräteträger ansehen', route('atemschutz.wearers'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
