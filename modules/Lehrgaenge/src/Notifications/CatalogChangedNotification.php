<?php

namespace Modules\Lehrgaenge\src\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To the Ausbildungsleiter: fox112 has new Lehrgänge to offer, or offered ones left fox112 – please decide */
class CatalogChangedNotification extends Notification
{
    use InApp;

    public function __construct(private readonly int $suggested, private readonly int $gone) {}

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject('Lehrgangsliste: bitte entscheiden')->greeting('Hallo '.$notifiable->name.',');
        if ($this->suggested) {
            $mail->line($this->suggested.' Lehrgang'.($this->suggested === 1 ? '' : 'e').' aus fox112 '.($this->suggested === 1 ? 'steht' : 'stehen').' noch nicht auf der Lehrgangsliste – anbieten oder nicht?');
        }
        if ($this->gone) {
            $mail->line($this->gone.' angebotene'.($this->gone === 1 ? 'r Lehrgang ist' : ' Lehrgänge sind').' nicht mehr in fox112 – weiter anbieten oder entfernen?');
        }

        return $mail->action('Zur Lehrgangsliste', route('lehrgaenge.courses'))->salutation(app(OrganizationSettings::class)->salutation());
    }
}
