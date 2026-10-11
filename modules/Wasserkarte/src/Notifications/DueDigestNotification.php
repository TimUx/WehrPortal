<?php

namespace Modules\Wasserkarte\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Wasserkarte\src\Models\Source;

/** To the Hydrantenwarte: inspections due in the next 30 days or overdue */
class DueDigestNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Collection $sources) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Wasserkarte: '.$this->sources->count().' Prüfung'.($this->sources->count() === 1 ? '' : 'en').' fällig')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Bei diesen Löschwasserquellen steht die Prüfung an:');
        $this->sources->take(30)->each(fn (Source $s) => $mail->line('– '.$s->next_inspection_on->format('d.m.Y').': '.$s->label().($s->location ? ', '.$s->location : '')));
        if ($this->sources->count() > 30) {
            $mail->line('… und '.($this->sources->count() - 30).' weitere.');
        }

        return $mail->action('Prüfungen ansehen', route('wasserkarte.inspections'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
