<?php

namespace Modules\Ausruestung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Models\Damage;

class DamageReportedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Damage $damage) {}

    public function toMail(object $notifiable): MailMessage
    {
        $equipment = $this->damage->equipment;

        return (new MailMessage)
            ->subject('Schaden gemeldet: '.$equipment->name.' ('.$equipment->code.')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($this->damage->reporter?->name ?? 'Jemand').' hat einen Schaden gemeldet. Das Gerät ist als defekt gekennzeichnet.')
            ->line('**'.$equipment->name.'** ('.$equipment->code.'), '.$equipment->placeLabel())
            ->line($this->damage->description)
            ->action('Gerät öffnen', EquipmentResource::getUrl('edit', ['record' => $equipment]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
