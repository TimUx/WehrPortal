<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Lehrgaenge\src\Models\Record;

/** To the Kamerad: an absolvierter Lehrgang needs a refresher */
class RefreshDueNotification extends Notification
{
    use InApp;

    public function __construct(private readonly Record $record) {}

    public function toMail(object $notifiable): MailMessage
    {
        $until = $this->record->validUntil();

        return (new MailMessage)
            ->subject('Auffrischung fällig: '.$this->record->course->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line('„'.$this->record->course->name.'“ '.($until->isPast() ? 'ist seit dem '.$until->format('d.m.Y').' nicht mehr aktuell' : 'braucht bis zum '.$until->format('d.m.Y').' eine Auffrischung').'. Melde dein Interesse an, die Ausbildungsleitung kümmert sich um den Platz.')
            ->action('Meine Lehrgänge', route('lehrgaenge.index'))
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
