<?php

namespace Modules\Kalender\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kalender\src\Models\Poll;

/** To the target group: a Terminabfrage to answer */
class PollCreatedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Poll $poll) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Terminabfrage: '.$this->poll->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('wann passt es dir? Für „'.$this->poll->title.'“ gibt es '.$this->poll->options()->count().' Terminvorschläge – bitte bei jedem ankreuzen, ob es passt.');
        if ($this->poll->deadline) {
            $mail->line('Bitte bis '.$this->poll->deadline->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr.');
        }

        return $mail->action('Termine ankreuzen', route('kalender.polls.show', $this->poll))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
