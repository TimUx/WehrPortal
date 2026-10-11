<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the old address: the login e-mail of this account was changed */
class EmailChangedNotification extends Notification
{
    public function __construct(private readonly User $user) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Deine E-Mail-Adresse wurde geändert')
            ->greeting('Hallo '.$this->user->name.',')
            ->line('Die E-Mail-Adresse deines Zugangs wurde auf '.$this->user->email.' geändert. Login-Links gehen ab jetzt dorthin.')
            ->line('Warst du das nicht, melde dich bitte bei den Admins.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
