<?php

namespace Modules\Wartung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Wartung\src\Models\Task;

/** To the Gerätewarte: Wartungen newly due (Voranzeige) and newly overdue */
class WartungDigestNotification extends Notification
{
    use InApp;

    /** Lines per group in the mail; the rest is on the page */
    private const LIMIT = 15;

    public function __construct(private readonly Collection $due, private readonly Collection $overdue) {}

    public function toMail(object $notifiable): MailMessage
    {
        $parts = array_filter([
            $this->overdue->isNotEmpty() ? $this->overdue->count().' überfällig' : null,
            $this->due->isNotEmpty() ? $this->due->count().' neu fällig' : null,
        ]);
        $mail = (new MailMessage)
            ->subject('Wartungen: '.implode(', ', $parts))
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Im Wartungsmanagement stehen Prüfungen an: '.implode(', ', $parts).'.');

        foreach (['Überfällig' => $this->overdue, 'Neu fällig' => $this->due] as $label => $tasks) {
            if ($tasks->isEmpty()) {
                continue;
            }
            $mail->line('**'.$label.':**');
            $tasks->take(self::LIMIT)->each(fn (Task $t) => $mail->line('– '.$t->due_on->format('d.m.Y').': '.$t->label().', '.$t->equipment?->code.' '.$t->equipment?->name));
            if ($tasks->count() > self::LIMIT) {
                $mail->line('… und '.($tasks->count() - self::LIMIT).' weitere.');
            }
        }

        return $mail->action('Wartungen ansehen', route('wartung.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
