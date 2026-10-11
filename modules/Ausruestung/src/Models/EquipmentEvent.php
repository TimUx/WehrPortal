<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line in the history of an item */
class EquipmentEvent extends Model
{
    public const TYPES = [
        'operation' => 'Einsatz',
        'exercise' => 'Übung',
        'inspection' => 'Prüfung',
        'loan' => 'Ausleihe',
        'return' => 'Rückgabe',
        'damage' => 'Schaden',
        'repair' => 'Reparatur',
        'recall' => 'Rückruf',
        'move' => 'Standort',
        'status' => 'Status',
        'note' => 'Notiz',
    ];

    /** Types anyone handling the item may log by hand */
    public const MANUAL_TYPES = ['operation', 'exercise', 'note'];

    protected $table = 'ar_events';

    protected $fillable = ['equipment_id', 'user_id', 'type', 'occurred_at', 'body'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
