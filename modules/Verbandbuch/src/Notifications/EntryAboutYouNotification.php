<?php

namespace Modules\Verbandbuch\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Verbandbuch\src\Models\Entry;

/** To the injured Kamerad: someone wrote an entry about him; he can read it and add a Nachtrag */
class EntryAboutYouNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Entry $entry) {}

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry;

        return (new MailMessage)
            ->subject('Eintrag im Verbandbuch über dich ('.$entry->code().')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($entry->recorded_by_name.' hat für dich einen Eintrag ins Verbandbuch geschrieben ('.$entry->kindLabel().' am '
                .$entry->occurred_at->setTimezone('Europe/Berlin')->format('d.m.Y').').')
            ->line('Der Eintrag ist wichtig, falls später Beschwerden auftreten – dann ist der Unfall im Dienst belegt. Wenn etwas fehlt oder nicht stimmt, schreib einen Nachtrag.')
            ->action('Eintrag ansehen', route('verbandbuch.show', $entry))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
