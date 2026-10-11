<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schichtplan\src\Models\Assignment;

/** To the Kamerad: the requested place is confirmed or not */
class ShiftDecidedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Assignment $assignment, private readonly bool $approved, private readonly ?string $reason = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        $a = $this->assignment->loadMissing(['plan', 'slot', 'station']);
        $shift = $a->slot->label().' Uhr an „'.$a->station->name.'“ bei „'.$a->plan->title.'“';
        $mail = (new MailMessage)
            ->subject(($this->approved ? 'Schicht bestätigt: ' : 'Schicht nicht bestätigt: ').$a->plan->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->approved ? 'deine Schicht '.$shift.' ist bestätigt. Danke!' : 'deine Anfrage für die Schicht '.$shift.' wurde nicht bestätigt.');
        if ($this->reason) {
            $mail->line('Begründung: '.$this->reason);
        }

        return $mail->action($this->approved ? 'Meine Schichten' : 'Andere Schicht wählen', route('schichtplan.signup', $a->plan))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
