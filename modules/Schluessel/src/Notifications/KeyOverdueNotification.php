<?php

namespace Modules\Schluessel\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schluessel\src\Models\Handover;

/** The Kamerad: the return date of a key has passed */
class KeyOverdueNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Handover $handover) {}

    public function toMail(object $notifiable): MailMessage
    {
        $key = $this->handover->key;

        return (new MailMessage)
            ->subject('Schlüssel zurückgeben: '.$key->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('der Schlüssel **'.$key->name.'** ('.$key->code.') sollte bis '.$this->handover->due_on->format('d.m.Y').' zurück sein. Bitte gib ihn beim Schlüsselwart ab.')
            ->action('Meine Schlüssel', route('profile').'#schluessel')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
