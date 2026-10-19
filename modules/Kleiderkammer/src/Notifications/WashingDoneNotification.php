<?php

namespace Modules\Kleiderkammer\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;

/** To the member: the Kleiderwart has marked the requested Wäsche as done */
class WashingDoneNotification extends Notification
{
    use InApp;

    public function __construct(private readonly EquipmentMessage $message) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Wäsche erledigt: '.$this->message->item_label)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('die Wäsche für „'.$this->message->item_label.'“ ist erledigt. Die Kleiderkammer sagt dir, wo du das Teil abholen kannst, falls es nicht schon zurück ist.')
            ->action('Meine Ausrüstung', route('kleiderkammer.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
