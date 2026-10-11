<?php

namespace Modules\Finanzen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Finanzen\src\Models\Entry;

/** To Kassenwart and Wehrführung: a Kamerad has submitted an Ausgabe to approve */
class ExpenseSubmittedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Entry $entry) {}

    public function toMail(object $notifiable): MailMessage
    {
        $entry = $this->entry->loadMissing(['submitter', 'costCenter']);

        return (new MailMessage)
            ->subject('Ausgabe zur Genehmigung: '.$entry->title.' ('.$entry->amount().')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($entry->submitter?->name ?? 'Ein Kamerad').' hat eine Ausgabe über **'.$entry->amount().'** eingereicht: '.$entry->title.'.')
            ->line('Kostenstelle: '.$entry->costCenter->label().' · Beleg '.$entry->code().' vom '.$entry->booked_on->format('d.m.Y'))
            ->action('Prüfen und genehmigen', route('finanzen.entry', $entry))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
