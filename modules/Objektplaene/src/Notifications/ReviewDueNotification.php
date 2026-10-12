<?php

namespace Modules\Objektplaene\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Objektplaene\src\Models\PlanObject;

/** The Planverwalter: objects whose plans are due for a review */
class ReviewDueNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, PlanObject>  $objects */
    public function __construct(private readonly Collection $objects) {}

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->objects->count();
        $mail = (new MailMessage)
            ->subject('Objektpläne: '.$count.' Überprüfung'.($count === 1 ? '' : 'en').' fällig')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('die Unterlagen dieser Objekte sollten überprüft werden – stimmen Pläne, Ansprechpartner und Zufahrt noch?');
        foreach ($this->objects as $object) {
            $mail->line('– '.$object->name.' (fällig seit '.$object->next_review_on->format('d.m.Y').')');
        }

        return $mail->action('Objektpläne öffnen', route('objektplaene.index', ['pruefung' => 1]))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
