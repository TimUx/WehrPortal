<?php

namespace Modules\Ausruestung\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Ausruestung\src\Models\Loan;

/** To the borrower: the item should have been back already */
class LoanOverdueNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Loan $loan) {}

    public function toMail(object $notifiable): MailMessage
    {
        $equipment = $this->loan->equipment;

        return (new MailMessage)
            ->subject('Rückgabe überfällig: '.$equipment->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Du hast **'.$equipment->name.'** ('.$equipment->code.') ausgeliehen. Die Rückgabe war für den '.$this->loan->due_at->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' vereinbart.')
            ->line('Bitte bring das Gerät zurück oder sprich mit dem Gerätewart.')
            ->action('Meine Ausleihen', route('ausruestung.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
