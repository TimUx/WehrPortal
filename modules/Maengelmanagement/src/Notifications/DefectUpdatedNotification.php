<?php

namespace Modules\Maengelmanagement\src\Notifications;

use App\Models\User;
use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Maengelmanagement\src\Models\Defect;

/** To the reporter or assignee: something happened to their defect */
class DefectUpdatedNotification extends Notification
{
    use InApp;

    public function __construct(
        private readonly Defect $defect,
        private readonly string $headline,
        private readonly User $by,
        private readonly ?string $text = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->headline.': '.$this->defect->title)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by->name.': '.$this->headline)
            ->line('**'.$this->defect->title.'** ('.$this->defect->subject().')');

        if (filled($this->text)) {
            $mail->line($this->text);
        }

        return $mail
            ->action('Mangel ansehen', route('maengelmanagement.show', $this->defect))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
