<?php

namespace App\Notifications;

use App\Models\FeedbackThread;
use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the member: an admin answered their feedback */
class FeedbackAnsweredNotification extends Notification
{
    use InApp;

    public function __construct(
        private readonly FeedbackThread $thread,
        private readonly User $admin,
        private readonly string $body,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Antwort auf dein Feedback: '.$this->thread->subject)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->admin->name.' hat auf dein Feedback geantwortet:')
            ->line($this->body)
            ->action('Gespräch ansehen', route('feedback.show', $this->thread))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
