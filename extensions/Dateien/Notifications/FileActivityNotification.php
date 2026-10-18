<?php

namespace Extensions\Dateien\Notifications;

use App\Notifications\Concerns\InApp;
use App\Services\OrganizationSettings;
use Extensions\Dateien\Models\Node;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** To a subscriber: who did what to which entry. Names only, never contents or comment texts. */
class FileActivityNotification extends Notification
{
    use InApp;

    /** @param  'created'|'version'|'comment'  $event */
    public function __construct(private readonly Node $node, private readonly string $by, private readonly string $event) {}

    public function toMail(object $notifiable): MailMessage
    {
        $what = match ($this->event) {
            'created' => $this->node->kind === 'folder' ? 'hat den Ordner „'.$this->node->name.'“ angelegt.' : 'hat die Datei „'.$this->node->name.'“ hochgeladen.',
            'version' => 'hat eine neue Version von „'.$this->node->name.'“ gespeichert.',
            default => 'hat „'.$this->node->name.'“ kommentiert.',
        };
        $subject = match ($this->event) {
            'created' => 'Neu in Dateien: ',
            'version' => 'Geändert: ',
            default => 'Neuer Kommentar: ',
        };

        return (new MailMessage)
            ->subject($subject.$this->node->name)
            ->greeting('Hallo '.$notifiable->name.',')
            ->line($this->by.' '.$what)
            ->action('In Dateien öffnen', route('dateien.index', ['node' => $this->node->id]))
            ->line('Du bekommst diese Nachricht, weil du diesen Eintrag oder einen Ordner darüber abonniert hast. Abbestellen im Explorer über „Abo beenden“.')
            ->salutation(app(OrganizationSettings::class)->salutation());
    }
}
