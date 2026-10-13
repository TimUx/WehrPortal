<?php

namespace Modules\Maengelmanagement\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Maengelmanagement\src\Models\Defect;

/** To the Verantwortlichen: defects nobody took on, unanswered Rückfragen and ones that stalled */
class DefectDigestNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, Defect>  $defects */
    public function __construct(private readonly Collection $defects) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Mängel brauchen Aufmerksamkeit ('.$this->defects->count().')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->defects->count().' '.($this->defects->count() === 1 ? 'Mangel bleibt' : 'Mängel bleiben').' liegen:');

        foreach ($this->defects as $defect) {
            $why = match (true) {
                $defect->assigned_to === null => 'noch niemand zuständig',
                $defect->question_open_at !== null => 'Rückfrage seit '.$defect->question_open_at->setTimezone('Europe/Berlin')->format('d.m.').' unbeantwortet',
                default => 'seit '.$defect->updated_at->setTimezone('Europe/Berlin')->format('d.m.Y').' unverändert'.($defect->assignee ? ' (zuständig: '.$defect->assignee->name.')' : ''),
            };
            $mail->line('- **'.$defect->title.'** ('.$defect->subject().', '.$defect->urgency->label().'): '.$why);
        }

        return $mail->action('Mängel ansehen', route('maengelmanagement.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
