<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentType extends Model
{
    protected $table = 'as_equipment_types';

    protected $fillable = [
        'code',
        'name',
        'family',
        'minimum_fill_pressure_bar',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_fill_pressure_bar' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<Equipment, $this> */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'type_id');
    }

    /** @return HasMany<EquipmentTypeEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EquipmentTypeEvent::class, 'type_id');
    }

    public function isCylinder(): bool
    {
        return $this->family === 'cylinder';
    }
}
