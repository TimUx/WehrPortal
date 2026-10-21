<?php

namespace Modules\Kleiderkammer\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Kleiderkammer\src\Filament\Resources\EquipmentMessageResource;
use Modules\Kleiderkammer\src\Models\EquipmentMessage;
use Modules\Kleiderkammer\src\Models\IssuedItem;
use Modules\Kleiderkammer\src\Services\PersonalAccess;

/** To the Kleiderwarte: a member asks for Wäsche of one or more items, with the reason */
class WashingRequestedNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, EquipmentMessage>  $requests */
    public function __construct(private readonly User $member, private readonly Collection $requests) {}

    public function toMail(object $notifiable): MailMessage
    {
        $access = app(PersonalAccess::class);
        if ($access->enabled()) {
            foreach ($this->requests as $request) {
                $issued = IssuedItem::withoutGlobalScopes()->findOrFail($request->issued_item_id);
                $access->authorizeIssuance($notifiable, $issued);
                abort_unless((int) $request->user_id === (int) $issued->user_id
                    && (int) $request->user_id === (int) $this->member->id, 403);
            }
        }
        $count = $this->requests->count();
        $mail = (new MailMessage)
            ->subject('Wäsche angefordert: '.$this->member->name.' ('.$count.' Teil'.($count === 1 ? '' : 'e').')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->member->name.' fordert die Wäsche an für: '.$this->requests->pluck('item_label')->implode(', ').'.')
            ->line('Begründung: '.$this->requests->first()->body);

        return $mail->action('In der Kleiderkammer öffnen', EquipmentMessageResource::getUrl('index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
