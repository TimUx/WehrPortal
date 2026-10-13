<?php

namespace App\Notifications;

use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/** Mail only: the Einladung link is a login link and never goes to the bell */
class InvitationNotification extends Notification
{
    public function __construct(private readonly string $url, private readonly Carbon $expiresAt, private readonly string $from) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $org = app(OrganizationSettings::class);

        return (new MailMessage)
            ->subject('Willkommen bei '.$org->shortName().' – deine Einladung')
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->from.' lädt dich zu openwehr ein – Termine, Mitteilungen, deine Personalakte und mehr, auf dem Handy und ohne Passwort.')
            ->action('Jetzt anmelden', $this->url)
            ->line('Der Link gilt bis '.$this->expiresAt->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i').' Uhr und nur einmal. Danach meldest du dich jederzeit mit deiner E-Mail-Adresse an.')
            ->salutation($org->salutation());
    }
}
