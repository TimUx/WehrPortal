<?php

namespace Modules\Finanzen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Finanzen\src\Models\Entry;

/** To the Kamerad: the Kassenwart has paid the approved Ausgabe */
class ExpensePaidNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Entry $entry) {}

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry;
        $mail = (new MailMessage)
            ->subject('Ausgabe bezahlt: '.$entry->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('der Kassenwart hat deine Ausgabe **'.$entry->title.'** über '.$entry->amount().' (Beleg '.$entry->code().') bezahlt.');

        if ($entry->payment_note) {
            $mail->line('Hinweis: '.$entry->payment_note);
        }

        return $mail->action('Ausgabe ansehen', route('finanzen.entry', $entry))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
