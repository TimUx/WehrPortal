<?php

namespace Modules\Unterweisungen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Unterweisungen running out: to the Kamerad, or as overview to the Unterweisungsbeauftragte */
class InstructionDueNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $lines */
    public function __construct(private readonly array $lines, private readonly bool $digest = false) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->digest ? 'Unterweisungen: '.count($this->lines).' Kameraden mit Fristen' : 'Unterweisung fällig')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->digest ? 'Bei diesen Kameraden läuft eine Unterweisung ab:' : 'bei dir steht eine Unterweisung an:');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->action($this->digest ? 'Zur Übersicht' : 'Meine Unterweisungen', route($this->digest ? 'unterweisungen.overview' : 'unterweisungen.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
