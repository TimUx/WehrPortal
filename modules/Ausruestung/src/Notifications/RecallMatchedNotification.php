<?php

namespace Modules\Ausruestung\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Ausruestung\src\Filament\Resources\EquipmentResource;
use Modules\Ausruestung\src\Filament\Resources\RecallResource;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Recall;
use Modules\Ausruestung\src\Services\EquipmentService;

/** Items were found for a manufacturer recall and locked */
class RecallMatchedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Recall $recall, private readonly Collection $equipment) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Rückruf: '.$this->equipment->count().' Gerät(e) gesperrt – '.$this->recall->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Für den Hersteller-Rückruf „'.$this->recall->title.'“ ('.$this->recall->manufacturer.') wurden diese Geräte gefunden und bis zur Klärung gesperrt:');

        foreach ($this->equipment->take(20) as $equipment) {
            /** @var Equipment $equipment */
            $mail->line('• '.$equipment->code.' – '.$equipment->name.($equipment->serial_number ? ', SN '.$equipment->serial_number : ''));
        }

        if ($this->equipment->count() > 20) {
            $mail->line('… und '.($this->equipment->count() - 20).' weitere.');
        }

        $url = $notifiable instanceof User && ! EquipmentService::canManageGlobal($notifiable)
            ? EquipmentResource::getUrl()
            : RecallResource::getUrl('edit', ['record' => $this->recall]);
        $label = $notifiable instanceof User && ! EquipmentService::canManageGlobal($notifiable)
            ? 'Ausrüstung öffnen'
            : 'Rückruf öffnen';

        return $mail
            ->action($label, $url)
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
