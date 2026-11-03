<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentEvent extends Model
{
    protected $table = 'as_equipment_events';

    protected $fillable = [
        'equipment_id',
        'kind',
        'from_status',
        'to_status',
        'from_organization',
        'to_organization',
        'from_location',
        'to_location',
        'notes',
        'user_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }
}
