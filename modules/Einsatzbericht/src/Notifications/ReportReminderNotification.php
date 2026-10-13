<?php

namespace Modules\Einsatzbericht\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Einsatzbericht\src\Models\Report;

/** Einsatzberichte left lying: still open with the Einsatzleiter, or waiting for the release. Only number, Stichwort and date. */
class ReportReminderNotification extends Notification
{
    use InApp;

    /** @param  Collection<int, Report>  $reports */
    public function __construct(private readonly Collection $reports, private readonly bool $toRelease) {}

    public function toMail(object $notifiable): MailMessage
    {
        $n = $this->reports->count();
        $mail = (new MailMessage)
            ->subject($this->toRelease ? 'Einsatzberichte warten auf die Freigabe ('.$n.')' : 'Einsatzberichte noch offen ('.$n.')')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->toRelease ? 'Diese Berichte sind seit über einer Woche abgeschlossen und warten auf die Freigabe:' : 'Diese Einsätze sind noch nicht abgeschlossen:');
        foreach ($this->reports as $report) {
            $mail->line('- '.$report->number.($report->keyword ? ' · '.$report->keyword : '').($report->alarmed_at ? ' vom '.$report->alarmed_at->setTimezone('Europe/Berlin')->format('d.m.Y') : ''));
        }

        return $mail->action($n === 1 ? 'Bericht öffnen' : 'Zu den Einsätzen', $n === 1 ? route('einsatzbericht.show', $this->reports->first()) : route('einsatzbericht.index', ['status' => $this->toRelease ? 'closed' : '']))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
