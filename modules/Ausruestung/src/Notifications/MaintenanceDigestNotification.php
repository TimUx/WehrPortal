<?php

namespace Modules\Ausruestung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Ausruestung\src\Filament\Pages\AusruestungDashboard;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Ausruestung\src\Models\Loan;

/** Daily overview for the Gerätewarte */
class MaintenanceDigestNotification extends Notification
{
    use InApp;

    public function __construct(private readonly array $summary) {}

    public function toMail(object $notifiable): MailMessage
    {
        $date = fn ($d) => $d?->format('d.m.Y') ?? '–';
        $mail = (new MailMessage)
            ->subject('Ausrüstung: Was ansteht')
            ->greeting('Hallo '.$notifiable->name.',');

        $sections = [
            'inspections' => ['Prüfungen fällig (nächste 30 Tage oder überfällig)', fn (Equipment $e) => $e->code.' – '.$e->name.': '.$date($e->next_inspection_at)],
            'lifetime' => ['Lebensdauer endet (nächste 90 Tage oder abgelaufen)', fn (Equipment $e) => $e->code.' – '.$e->name.': '.$date($e->lifetimeEndsAt())],
            'locked' => ['Gesperrt wegen Rückruf', fn (Equipment $e) => $e->code.' – '.$e->name],
            'damages' => ['Offene Schäden', fn ($d) => $d->equipment->code.' – '.$d->equipment->name.': '.$d->status->label()],
            'overdue_loans' => ['Überfällige Ausleihen', fn (Loan $l) => $l->equipment->code.' – '.$l->equipment->name.' bei '.$l->user->name.' (seit '.$date($l->due_at->copy()->setTimezone('Europe/Berlin')).')'],
        ];

        foreach ($sections as $key => [$title, $line]) {
            $items = $this->summary[$key];
            if ($items->isEmpty()) {
                continue;
            }

            $mail->line('**'.$title.' ('.$items->count().')**');
            foreach ($items->take(15) as $item) {
                $mail->line('• '.$line($item));
            }
            if ($items->count() > 15) {
                $mail->line('… und '.($items->count() - 15).' weitere.');
            }
        }

        return $mail
            ->action('Zur Ausrüstungsverwaltung', AusruestungDashboard::getUrl())
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
