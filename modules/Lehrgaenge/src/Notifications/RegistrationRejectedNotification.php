<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad: the Ausbildungsleitung has declined the registration, with its comment */
class RegistrationRejectedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Lehrgangsanmeldung abgelehnt: '.$this->record->course->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('deine Anmeldung für den Lehrgang **'.$this->record->course->name.'** wurde von der Ausbildungsleitung abgelehnt.')
            ->line('Kommentar: '.$this->record->cancel_reason)
            ->line('Bei Fragen wende dich bitte an die Ausbildungsleitung.')
            ->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
