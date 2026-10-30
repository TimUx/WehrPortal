<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A timed comment or special event in the log of an Überwachung */
class LogEntry extends Model
{
    public const EVENTS = [
        'person' => 'Person gefunden', 'gas' => 'Gasflasche gefunden', 'ventilation' => 'Belüftung geschaffen',
        'fire_out' => 'Feuer unter Kontrolle', 'mayday' => 'Mayday', 'other' => 'Sonstiges',
    ];

    protected $table = 'as_log';

    public $timestamps = false;

    protected $fillable = ['operation_id', 'kind', 'event', 'body', 'noted_at', 'user_id'];

    protected function casts(): array
    {
        return ['noted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return $this->kind === 'event' ? (self::EVENTS[$this->event] ?? 'Ereignis') : 'Kommentar';
    }
}
