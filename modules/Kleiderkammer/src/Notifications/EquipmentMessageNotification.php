<?php

namespace Modules\Kleiderkammer\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;

/** To the Kleiderwarte: a member wrote about an item of their equipment */
class EquipmentMessageNotification extends Notification
{
    use InApp;

    public function __construct(private readonly EquipmentMessage $message) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nachricht zur Ausrüstung: '.$this->message->item_label)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->message->user->name.' hat eine Nachricht zu „'.$this->message->item_label.'“ geschickt:')
            ->line($this->message->body)
            ->action('In der Kleiderkammer öffnen', EquipmentMessageResource::getUrl('index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
