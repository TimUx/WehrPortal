<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Event;

/** To Kameraden who have not answered yet */
class RsvpReminderNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Event $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->event->rsvp_deadline?->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i');

        return (new MailMessage)
            ->subject('Rückmeldung fehlt: '.$this->event->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Für **'.$this->event->title.'** ('.$this->event->timeLabel().') fehlt noch deine Rückmeldung'.($deadline ? ', bitte bis '.$deadline.' Uhr' : '').'.')
            ->action('Jetzt zusagen oder absagen', route('kalender.show', $this->event))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
