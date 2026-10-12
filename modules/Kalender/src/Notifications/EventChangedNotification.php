<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Event;

/** A Termin was moved or changed, or takes place after all: with the old time and place */
class EventChangedNotification extends Notification
{
    use InApp;

    public function __construct(
        private readonly Event $event,
        private readonly ?string $oldTime = null,
        private readonly ?string $oldLocation = null,
        private readonly bool $reinstated = false,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $event = $this->event;
        $mail = (new MailMessage)
            ->subject(($this->reinstated ? 'Findet doch statt: ' : 'Geändert: ').$event->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->reinstated
                ? 'der abgesagte Termin **'.$event->title.'** am '.$event->timeLabel().' findet doch statt.'
                : 'der Termin **'.$event->title.'** hat sich geändert. Jetzt: '.$event->timeLabel().($event->location ? ', '.$event->location : '').'.');

        if ($this->oldTime && $this->oldTime !== $event->timeLabel()) {
            $mail->line('Bisher: '.$this->oldTime);
        }
        if ($this->oldLocation !== null && $this->oldLocation !== (string) $event->location) {
            $mail->line('Ort bisher: '.($this->oldLocation !== '' ? $this->oldLocation : 'keiner'));
        }

        return $mail
            ->action($event->rsvp ? 'Zum Termin und Rückmeldung' : 'Zum Termin', route('kalender.show', $event))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
