<?php

namespace Modules\Personalverwaltung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Personalverwaltung\src\Models\ChangeRequest;

/** To the responsible Personalverwalter: a Kamerad reports that something in their Personalakte is wrong */
class ChangeRequestedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly ChangeRequest $request) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Änderungswunsch von '.$this->request->user->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->request->user->name.' meldet einen Änderungswunsch zur Personalakte ('.$this->request->topicLabel().'):')
            ->line('„'.$this->request->message.'“')
            ->action('Änderungswünsche öffnen', route('personalverwaltung.requests'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
