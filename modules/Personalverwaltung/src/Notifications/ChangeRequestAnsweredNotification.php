<?php

namespace Modules\Personalverwaltung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Personalverwaltung\src\Models\ChangeRequest;

/** To the Kamerad: the Personalverwalter has dealt with the Änderungswunsch */
class ChangeRequestAnsweredNotification extends Notification
{
    use InApp;

    public function __construct(private readonly ChangeRequest $request) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Dein Änderungswunsch: '.$this->request->statusLabel())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('dein Änderungswunsch zur Personalakte ('.$this->request->topicLabel().') ist '.($this->request->status === 'done' ? 'erledigt.' : 'nicht übernommen worden.'));
        if ($this->request->answer) {
            $mail->line('Antwort: „'.$this->request->answer.'“');
        }

        return $mail->action('Zur Personalakte', route('personalverwaltung.own'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
