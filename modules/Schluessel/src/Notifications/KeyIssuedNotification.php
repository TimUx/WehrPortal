<?php

namespace Modules\Schluessel\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schluessel\src\Models\Handover;

/** The Kamerad got a key */
class KeyIssuedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Handover $handover) {}

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->handover->key;

        return (new MailMessage)
            ->subject('Schlüssel ausgegeben: '.$key->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('dir wurde der Schlüssel **'.$key->name.'** ('.$key->code.') ausgegeben'.($this->handover->due_on ? ', Rückgabe bis '.$this->handover->due_on->format('d.m.Y') : '').'.')
            ->line('Geht er verloren, melde es bitte sofort unter „Meine Schlüssel“.')
            ->action('Meine Schlüssel', route('profile').'#schluessel')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
