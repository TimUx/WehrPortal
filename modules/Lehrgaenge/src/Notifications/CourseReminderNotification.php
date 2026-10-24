<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad, 14 days before the first day */
class CourseReminderNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Dein Lehrgang beginnt bald: '.$this->record->course->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('am '.$this->record->starts_on->format('d.m.Y').' beginnt dein Lehrgang **'.$this->record->course->name.'**:');

        foreach ($this->record->sessions as $session) {
            $mail->line('• '.$session->label());
        }

        return $mail->line('Kannst du nicht teilnehmen, sag den Lehrgang bitte sofort in WehrPortal ab.')
            ->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
