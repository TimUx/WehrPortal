<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Ausbildungsleiter: a Kamerad is interested in a Lehrgang */
class RequestReceivedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Interesse an Lehrgang: '.$this->record->course->name.' – '.$this->record->user->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->record->user->name.' hat Interesse am Lehrgang **'.$this->record->course->name.'** gemeldet. Bitte in fox112 Bedarf anmelden.');

        if ($this->record->notes) {
            $mail->line('Bemerkung: '.$this->record->notes);
        }

        return $mail->line('Bitte den Kameraden in fox112 anmelden und nach der Bestätigung die Termine in WehrPortal eintragen.')
            ->action('Lehrgangsanmeldungen', route('lehrgaenge.registrations'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
