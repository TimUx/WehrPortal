<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/** An entry under the bell; kept 180 days like the logs */
class UserNotification extends DatabaseNotification
{
    use MassPrunable;

    public const KEEP_DAYS = 180;

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }

    public function title(): string
    {
        return (string) ($this->data['title'] ?? 'Benachrichtigung');
    }

    public function body(): string
    {
        return (string) ($this->data['body'] ?? '');
    }

    public function module(): ?string
    {
        return $this->data['module'] ?? null;
    }
}
