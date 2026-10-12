<?php

namespace Modules\Einsatzbericht\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Einsatzbericht\src\Models\Report;

/**
 * A closed report waits for the Wehrführung, or the Wehrführung handed it back to the
 * Einsatzleiter. Only number, Stichwort and date, no address or people.
 */
class ReportStatusNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Report $report, private readonly User $by, private readonly bool $returned = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $report = $this->report;
        $label = 'Einsatz '.$report->number.($report->keyword ? ' ('.$report->keyword.')' : '')
            .($report->alarmed_at ? ' vom '.$report->alarmed_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y') : '');

        return (new MailMessage)
            ->subject(($this->returned ? 'Einsatzbericht zurückgegeben: ' : 'Einsatzbericht zur Freigabe: ').$report->number)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->returned
                ? $this->by->name.' hat den Bericht zum '.$label.' zur Überarbeitung zurückgegeben.'
                : $this->by->name.' hat den Bericht zum '.$label.' abgeschlossen. Er wartet auf die Freigabe.')
            ->action($this->returned ? 'Bericht überarbeiten' : 'Bericht prüfen und freigeben', route('einsatzbericht.show', $report))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
