<?php

namespace Modules\Fahrzeuge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Fahrzeuge\src\Models\Deadline;

/** To the Fahrzeugwarte: vehicle deadlines newly due and newly overdue */
class DeadlineDigestNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Collection $due, private readonly Collection $overdue) {}

    public function toMail(object $notifiable): MailMessage
    {
        $parts = array_filter([
            $this->overdue->isNotEmpty() ? $this->overdue->count().' überfällig' : null,
            $this->due->isNotEmpty() ? $this->due->count().' bald fällig' : null,
        ]);
        $mail = (new MailMessage)
            ->subject('Fahrzeugfristen: '.implode(', ', $parts))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Bei den Fahrzeugen stehen Fristen an: '.implode(', ', $parts).'.');
        foreach (['Überfällig' => $this->overdue, 'Bald fällig' => $this->due] as $label => $deadlines) {
            if ($deadlines->isNotEmpty()) {
                $mail->line('**'.$label.':**');
                $deadlines->each(fn (Deadline $d) => $mail->line('– '.$d->due_on->format('d.m.Y').': '.$d->vehicle->name.' – '.$d->title));
            }
        }

        return $mail->action('Fahrzeuge ansehen', route('fahrzeuge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
