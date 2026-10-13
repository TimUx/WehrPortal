<?php

namespace Modules\Personalverwaltung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Kamerad's Änderungswunsch to their Personalakte: open until a Personalverwalter answers */
class ChangeRequest extends Model
{
    public const TOPICS = [
        'stammdaten' => 'Name, E-Mail, Eintritt, Status', 'laufbahn' => 'Dienstgrad, Abteilung, Funktion', 'ehrungen' => 'Ehrungen',
        'ausweis' => 'Dienstausweis', 'lehrgaenge' => 'Lehrgänge', 'atemschutz' => 'Atemschutz', 'sonstiges' => 'Sonstiges',
    ];

    public const STATUSES = ['open' => 'Offen', 'done' => 'Erledigt', 'declined' => 'Nicht übernommen'];

    protected $table = 'pm_change_requests';

    protected $fillable = ['user_id', 'topic', 'message', 'status', 'answer', 'handled_by', 'handled_at'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function topicLabel(): string
    {
        return self::TOPICS[$this->topic] ?? $this->topic;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
