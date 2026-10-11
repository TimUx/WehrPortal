<?php

namespace Modules\Wasserkarte\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Wasserkarte\src\Models\Defect;

/** To the Hydrantenwarte: a Kamerad reported a defect; the source is marked defective */
class DefectReportedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Defect $defect) {}

    public function toMail(object $notifiable): MailMessage
    {
        $source = $this->defect->source;

        return (new MailMessage)
            ->subject('Wasserkarte: Mangel an '.$source->label())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($this->defect->reporter?->name ?? 'Ein Kamerad').' meldet einen Mangel an '.$source->label().($source->location ? ' ('.$source->location.')' : '').': '.$this->defect->description)
            ->line('Die Wasserquelle steht jetzt auf „defekt“, bis der Mangel behoben ist.')
            ->action('Wasserquelle ansehen', route('wasserkarte.source', $source))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
