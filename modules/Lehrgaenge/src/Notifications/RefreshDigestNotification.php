<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Ausbildungsleiter: whose refresher became due */
class RefreshDigestNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Collection $records) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Auffrischungen fällig ('.$this->records->count().')')->greeting('Hallo '.$notifiable->name.',')
            ->line('Bei diesen Kameraden steht eine Auffrischung an:');
        foreach ($this->records as $r) {
            /** @var Record $r */
            $mail->line($r->user->name.': '.$r->course->name.' bis '.$r->validUntil()->format('d.m.Y'));
        }

        return $mail->action('Ausbildungsstand', route('lehrgaenge.qualifications'))->salutation(app(OrganizationSettings::class)->salutation());
    }
}
