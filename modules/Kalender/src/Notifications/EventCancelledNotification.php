<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Event;

class EventCancelledNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Event $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Abgesagt: '.$this->event->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Der Termin **'.$this->event->title.'** am '.$this->event->timeLabel().' fällt aus.')
            ->action('Zum Termin', route('kalender.show', $this->event))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
