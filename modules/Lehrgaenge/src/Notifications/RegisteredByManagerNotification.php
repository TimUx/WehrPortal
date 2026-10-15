<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad: the Ausbildungsleitung has put them down for a Lehrgang */
class RegisteredByManagerNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record, private readonly string $by) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Für einen Lehrgang angemeldet: '.$this->record->course->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by.' hat dich für den Lehrgang **'.$this->record->course->name.'** angemeldet.')
            ->line('Sobald die Termine feststehen, bekommst du sie per E-Mail. Passt es dir nicht, kannst du unter „Meine Lehrgänge“ mit Begründung absagen.');
        if (filled($this->record->notes)) {
            $mail->line('Bemerkung: '.$this->record->notes);
        }

        return $mail->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
