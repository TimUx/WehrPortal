<?php

namespace Modules\Maengelmanagement\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Maengelmanagement\src\Enums\Urgency;
use Modules\Maengelmanagement\src\Filament\Resources\DefectResource;
use Modules\Maengelmanagement\src\Models\Defect;

/** To the people who look after defects: a new defect was reported */
class DefectReportedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Defect $defect) {}

    public function toMail(object $notifiable): MailMessage
    {
        $defect = $this->defect;
        $critical = $defect->urgency === Urgency::Critical;

        $mail = (new MailMessage)
            ->subject(($critical ? 'DRINGEND – ' : '').'Neuer Mangel: '.$defect->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($defect->reporter->name.' hat einen Mangel gemeldet.')
            ->line('**'.$defect->title.'**')
            ->line('Betrifft: '.$defect->subject())
            ->line('Dringlichkeit: '.$defect->urgency->label());

        if (filled($defect->description)) {
            $mail->line($defect->description);
        }

        return $mail
            ->action('Mangel bearbeiten', DefectResource::getUrl('edit', ['record' => $defect]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
