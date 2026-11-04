<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentTypeEvent extends Model
{
    protected $table = 'as_equipment_type_events';

    protected $fillable = [
        'type_id',
        'from_name',
        'to_name',
        'from_minimum_fill_pressure_bar',
        'to_minimum_fill_pressure_bar',
        'from_is_active',
        'to_is_active',
        'user_id',
        'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_minimum_fill_pressure_bar' => 'integer',
            'to_minimum_fill_pressure_bar' => 'integer',
            'from_is_active' => 'boolean',
            'to_is_active' => 'boolean',
            'changed_at' => 'immutable_datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'type_id');
    }
}
