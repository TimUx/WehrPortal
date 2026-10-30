<?php

namespace Modules\Unterweisungen\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Unterweisungen\src\Services\InstructionService;

/** Expiring Unterweisungen: to the Kamerad, or as overview to specialists and local Wehrführung */
class InstructionDueNotification extends Notification
{
    use InApp;

    /** @param  list<string>  $lines */
    public function __construct(private readonly array $lines, private readonly bool $digest = false) {}

    /** @return array{url: ?string} */
    public function inApp(object $notifiable): array
    {
        return ['url' => $this->toMail($notifiable)->actionUrl];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->digest ? 'Unterweisungen: '.count($this->lines).' Kameraden mit Fristen' : 'Unterweisung fällig')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->digest ? 'Bei diesen Kameraden läuft eine Unterweisung ab:' : 'bei dir steht eine Unterweisung an:');
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        if (! $this->digest || ($notifiable instanceof User && InstructionService::canManage($notifiable))) {
            $mail->action($this->digest ? 'Zur Übersicht' : 'Meine Unterweisungen', route($this->digest ? 'unterweisungen.overview' : 'unterweisungen.index'));
        }

        return $mail->salutation(app(OrganizationSettings::class)->salutation());
    }
}
