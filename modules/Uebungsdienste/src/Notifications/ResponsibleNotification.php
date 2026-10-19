<?php

namespace Modules\Uebungsdienste\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Uebungsdienste\src\Models\Service;

/** The Wehrführung made the Kamerad responsible for an Übungsdienst */
class ResponsibleNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Service $service, private readonly ?User $by = null) {}

    public function toMail(object $notifiable): MailMessage
    {
        $service = $this->service;
        $when = $service->dateLabel().($service->starts_at ? ', '.$service->starts_at.' Uhr' : '').($service->location ? ', '.$service->location : '');

        $mail = (new MailMessage)
            ->subject('Übungsdienst am '.$service->dateLabel().': du bist verantwortlich')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line(($this->by ? $this->by->name.' hat dich' : 'Du bist').' für den Übungsdienst am '.$when.' als verantwortlich eingetragen.')
            ->line($service->topic ? 'Thema: '.$service->topic : 'Ein Thema steht noch nicht fest.');

        // The plan is restricted to the Wehrführung; others get the details in the mail only
        if ($this->mayOpen($notifiable)) {
            $mail->action('Übungsdienstplan ansehen', route('uebungsdienste.index'));
        }

        return $mail->salutation(app(OrganizationSettings::class)->salutation());
    }

    public function inApp(object $notifiable): array
    {
        return $this->mayOpen($notifiable) ? [] : ['url' => route('home')];
    }

    private function mayOpen(object $notifiable): bool
    {
        return $notifiable instanceof User && $notifiable->canUseModule('uebungsdienste');
    }
}
