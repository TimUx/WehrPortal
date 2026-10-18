<?php

namespace Extensions\Dateien\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Extensions\Dateien\Models\Node;
use Extensions\Dateien\Models\Space;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To a Kamerad who got a share (directly or through a Dateigruppe): names and level only */
class SharedWithYouNotification extends Notification
{
    use InApp;

    public const LEVELS = ['read' => 'Lesen', 'edit' => 'Bearbeiten', 'manage' => 'Verwalten'];

    public function __construct(private readonly Space|Node $target, private readonly string $by, private readonly string $level) {}

    public function toMail(object $notifiable): MailMessage
    {
        $kind = match (true) {
            $this->target instanceof Space => 'die Ablage',
            $this->target->kind === 'folder' => 'den Ordner',
            default => 'die Datei',
        };
        $url = $this->target instanceof Space
            ? route('dateien.index', ['space' => $this->target->id])
            : route('dateien.index', ['node' => $this->target->id]);

        return (new MailMessage)
            ->subject('Mit dir geteilt: '.$this->target->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by.' hat dir '.$kind.' „'.$this->target->name.'“ freigegeben (Recht: '.(self::LEVELS[$this->level] ?? $this->level).').')
            ->action('In Dateien öffnen', $url)
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
