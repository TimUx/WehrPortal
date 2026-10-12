<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schichtplan\src\Models\Plan;

/** The planer changed the Kamerad's shifts after publishing: here is what applies now */
class ShiftsChangedNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $shifts  what the Kamerad has now, empty when off the plan */
    public function __construct(private readonly Plan $plan, private readonly array $shifts, private readonly ?User $by = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        $plan = $this->plan;
        $mail = (new MailMessage)
            ->subject('Schichten geändert: '.$plan->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($this->by ? $this->by->name.' hat' : 'Der Planer hat').' deine Schichten für „'.$plan->title.'“ ('.$plan->timeLabel().') geändert.'
                .($this->shifts === [] ? ' Du bist dort jetzt nicht mehr eingeteilt.' : ' Jetzt gilt:'));
        foreach ($this->shifts as $shift) {
            $mail->line('– '.$shift);
        }

        $mayOpen = $notifiable instanceof User && $notifiable->can('view', $plan);

        return $mail
            ->action($mayOpen ? 'Schichtplan ansehen' : 'Meine Schichten', $mayOpen ? route('schichtplan.show', $plan) : route('schichtplan.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
