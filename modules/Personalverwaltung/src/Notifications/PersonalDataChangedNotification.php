<?php

namespace Modules\Personalverwaltung\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The Personalverwalter: a Kamerad changed his personal data – which fields, never the values */
class PersonalDataChangedNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $fields  e.g. ['Anschrift', 'Mobilnummer'] */
    public function __construct(private readonly User $member, private readonly array $fields) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Persönliche Daten geändert: '.$this->member->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->member->name.' hat in der Personalakte geändert: '.implode(', ', $this->fields).'.')
            ->line('Die neuen Angaben stehen in der Personalverwaltung.')
            ->action('Personalakte ansehen', route('personalverwaltung.member', $this->member))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
