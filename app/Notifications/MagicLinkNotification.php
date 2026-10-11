<?php

namespace App\Notifications;

use App\Services\OrganizationSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MagicLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $rawToken) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('auth.magic-link.confirm', ['token' => $this->rawToken]);
        $ttl = config('magic-link.ttl_minutes', 15);

        $org = app(OrganizationSettings::class);

        return (new MailMessage)
            ->subject('Dein Login-Link für '.$org->shortName())
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('Jemand hat einen Login-Link für dein Konto angefordert.')
            ->action('Jetzt anmelden', $url)
            ->line("Dieser Link ist {$ttl} Minuten gültig und kann nur einmal verwendet werden.")
            ->line('Falls du keinen Link angefordert hast, kannst du diese E-Mail ignorieren.')
            ->salutation($org->salutation());
    }
}
