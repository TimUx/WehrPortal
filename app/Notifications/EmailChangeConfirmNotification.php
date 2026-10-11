<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the NEW address: confirm that it should become the login address */
class EmailChangeConfirmNotification extends Notification
{
    public function __construct(private readonly User $user, private readonly string $rawToken) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = app(OrganizationSettings::class);
        $hours = config('magic-link.email_change_ttl_hours', 24);

        return (new MailMessage)
            ->subject('Bitte bestätige deine neue E-Mail-Adresse')
            ->greeting('Hallo '.$this->user->name.',')
            ->line('Für deinen Zugang bei '.$org->shortName().' wurde diese Adresse als neue E-Mail-Adresse angegeben. Bestätige sie, damit Login-Links künftig hierher gehen.')
            ->action('E-Mail-Adresse bestätigen', route('auth.email-change.confirm', ['token' => $this->rawToken]))
            ->line("Der Link ist {$hours} Stunden gültig. Bis du bestätigst, gilt weiter deine bisherige Adresse.")
            ->line('Warst du das nicht, ignoriere diese E-Mail einfach.')
            ->salutation($org->salutation());
    }
}
