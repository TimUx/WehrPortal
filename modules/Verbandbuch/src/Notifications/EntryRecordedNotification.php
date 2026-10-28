<?php

namespace Modules\Verbandbuch\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Verbandbuch\src\Models\Entry;

/** To the responsible local Wehrführung and global GBI/admins: a new entry, without health details. */
class EntryRecordedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Entry $entry) {}

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry;
        $mail = (new MailMessage)
            ->subject('Neuer Eintrag im Verbandbuch: '.$entry->kindLabel().' ('.$entry->code().')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($entry->recorded_by_name.' hat einen Eintrag ins Verbandbuch geschrieben: '.$entry->kindLabel().' am '
                .$entry->occurred_at->setTimezone('Europe/Berlin')->format('d.m.Y').' ('.$entry->occasionLabel().').')
            ->line('Die Einzelheiten stehen aus Datenschutzgründen nur in WehrPortal.');

        if ($entry->needsReport()) {
            $mail->line('Die verletzte Person war beim Arzt. Bitte prüfen, ob eine **Unfallanzeige an die Unfallkasse** nötig ist.');
        }

        return $mail->action('Eintrag ansehen', route('verbandbuch.show', $entry))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
