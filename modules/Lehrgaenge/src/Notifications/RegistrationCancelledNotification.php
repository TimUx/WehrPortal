<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Ausbildungsleiter: a Kamerad has cancelled and has to be signed off in fox112 */
class RegistrationCancelledNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Lehrgang abgesagt: '.$this->record->course->name.' – '.$this->record->user->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->record->user->name.' hat den Lehrgang **'.$this->record->course->name.'** abgesagt.')
            ->line('Grund: '.$this->record->cancel_reason);

        foreach ($this->record->sessions as $session) {
            $mail->line('• '.$session->label());
        }

        return $mail->line('Bitte den Kameraden in fox112 wieder abmelden.')
            ->action('Lehrgangsanmeldungen', route('lehrgaenge.registrations'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
