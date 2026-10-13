<?php

namespace Modules\Schluessel\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schluessel\src\Models\Key;

/** The Schlüsselwarte: a key is lost – maybe a lock has to be changed */
class KeyLostNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Key $key, private readonly User $by) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Schlüssel verloren: '.$this->key->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by->name.' hat den Schlüssel **'.$this->key->name.'** ('.$this->key->code.') als verloren gemeldet.'.($this->key->opensLabel() ? ' Er schließt: '.$this->key->opensLabel().'.' : ''))
            ->line('Prüft, ob das Schloss getauscht oder die Berechtigung gesperrt werden muss.')
            ->action('Schlüssel ansehen', route('schluessel.show', $this->key))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
