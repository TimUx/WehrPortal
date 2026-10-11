<?php

namespace Modules\Mitteilungen\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Mitteilungen\src\Models\Announcement;

class AnnouncementNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Announcement $announcement) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->announcement->important ? 'Wichtig: ' : 'Mitteilung: ').$this->announcement->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('**'.$this->announcement->title.'**');

        if (filled($this->announcement->body)) {
            $mail->line($this->announcement->body);
        }

        return $mail
            ->action('Zu den Mitteilungen', route('mitteilungen.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
