<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Schichtplan\src\Models\Assignment;
use Modules\Schichtplan\src\Models\Plan;

/** To the Kamerad, the day before: the own shifts */
class ShiftReminderNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, Assignment>  $shifts */
    public function __construct(private readonly Plan $plan, private readonly Collection $shifts) {}

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->shifts->sortBy(fn (Assignment $a) => $a->slot->starts_at)->first();
        $mail = (new MailMessage)
            ->subject('Erinnerung: deine Schicht '.$first->slot->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m. H:i').' Uhr – '.$this->plan->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('bald geht es los: „'.$this->plan->title.'“'.($this->plan->location ? ' in '.$this->plan->location : '').'. Deine Schichten:');
        $this->shifts->sortBy(fn (Assignment $a) => $a->slot->starts_at)
            ->each(fn (Assignment $a) => $mail->line('– '.$a->slot->starts_at->copy()->setTimezone('Europe/Berlin')->format('d.m.').' '.$a->slot->label().' Uhr: '.$a->station->name));

        return $mail->line('Kannst du nicht? Bitte rechtzeitig austragen oder dem Planer Bescheid geben.')
            ->action('Schichtplan ansehen', route('schichtplan.show', $this->plan))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
