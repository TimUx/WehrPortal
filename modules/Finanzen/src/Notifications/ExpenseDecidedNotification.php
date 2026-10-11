<?php

namespace Modules\Finanzen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Finanzen\src\Models\Entry;

/** To the Kamerad: the Ausgabe is approved or rejected, with the comment */
class ExpenseDecidedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Entry $entry) {}

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry;
        $approved = $entry->status === 'approved';
        $mail = (new MailMessage)
            ->subject(($approved ? 'Ausgabe genehmigt: ' : 'Ausgabe abgelehnt: ').$entry->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('deine Ausgabe **'.$entry->title.'** über '.$entry->amount().' (Beleg '.$entry->code().') wurde '.($approved ? 'genehmigt. Der Kassenwart kümmert sich um die Zahlung.' : 'abgelehnt.'));

        if ($entry->decision_comment) {
            $mail->line('Kommentar: '.$entry->decision_comment);
        }

        return $mail->action('Ausgabe ansehen', route('finanzen.entry', $entry))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
