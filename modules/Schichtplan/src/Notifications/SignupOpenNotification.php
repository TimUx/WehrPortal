<?php

namespace Modules\Schichtplan\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Schichtplan\src\Models\Plan;

/** To the target group: a plan is open for self sign-up */
class SignupOpenNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Plan $plan) {}

    public function toMail(object $notifiable): MailMessage
    {
        $deadline = $this->plan->signupDeadline()->copy()->setTimezone('Europe/Berlin');

        return (new MailMessage)
            ->subject('Helfer gesucht: '.$this->plan->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('für „'.$this->plan->title.'“ ('.$this->plan->timeLabel().($this->plan->location ? ', '.$this->plan->location : '').') werden Helfer gesucht.')
            ->line('Such dir freie Schichten aus und trag dich selbst ein – bis '.$deadline->format('d.m.Y H:i').' Uhr.'
                .($this->plan->needs_approval ? ' Die Eintragung wird danach bestätigt.' : ''))
            ->action('Schichten ansehen und eintragen', route('schichtplan.signup', $this->plan))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
