<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad: fox112 has confirmed the Lehrgang, with the days (or they have changed) */
class CourseConfirmedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record, private readonly bool $changed = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $course = $this->record->course->name;
        $mail = (new MailMessage)
            ->subject(($this->changed ? 'Lehrgangstermine geändert: ' : 'Lehrgang bestätigt: ').$this->record->course->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->changed
                ? 'die Termine für deinen Lehrgang **'.$course.'** haben sich geändert. Es gilt jetzt:'
                : 'deine Anmeldung für den Lehrgang **'.$course.'** ist bestätigt. Die Termine:');

        foreach ($this->record->sessions as $session) {
            $mail->line('• '.$session->label());
        }

        return $mail->line('Kannst du doch nicht teilnehmen, sag den Lehrgang bitte so früh wie möglich in WehrPortal ab.')
            ->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
