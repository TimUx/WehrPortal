<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad: the Ausbildungsleitung has registered the Interesse in fox112, the Kamerad is on the Warteliste */
class WaitlistedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Auf der Warteliste: '.$this->record->course->getAttribute('name'))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('dein Interesse am Lehrgang **'.$this->record->course->getAttribute('name').'** ist in fox112 angemeldet – du stehst auf der Warteliste.')
            ->line('Sobald ein Platz bestätigt ist, bekommst du die Termine per E-Mail. Passt es dir nicht mehr, kannst du unter „Meine Lehrgänge“ mit Begründung absagen.')
            ->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
