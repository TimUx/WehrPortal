<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Poll;

/** To who has not answered: the Terminabfrage closes soon */
class PollReminderNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Poll $poll) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Erinnerung Terminabfrage: '.$this->poll->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('du hast bei der Terminabfrage „'.$this->poll->title.'“ noch nicht angekreuzt. Sie läuft bis '
                .$this->poll->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr.')
            ->action('Termine ankreuzen', route('kalender.polls.show', $this->poll))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
