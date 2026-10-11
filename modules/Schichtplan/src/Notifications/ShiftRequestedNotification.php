<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schichtplan\src\Models\Assignment;

/** To the planer and the Stationsverantwortung: someone asks for a place */
class ShiftRequestedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Assignment $assignment) {}

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->assignment->loadMissing(['plan', 'slot', 'station', 'user']);

        return (new MailMessage)
            ->subject('Schicht angefragt: '.$a->user->name.' – '.$a->plan->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($a->user->name.' möchte die Schicht '.$a->slot->label().' Uhr an „'.$a->station->name.'“ bei „'.$a->plan->title.'“ übernehmen.')
            ->line('Bitte bestätigen oder ablehnen – bis dahin ist der Platz reserviert.')
            ->action('Anfrage ansehen', route('schichtplan.signup', $a->plan))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
