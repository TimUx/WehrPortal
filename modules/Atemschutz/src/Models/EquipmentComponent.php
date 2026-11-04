<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentComponent extends Model
{
    protected $table = 'as_equipment_components';

    protected $fillable = [
        'equipment_id',
        'component_equipment_id',
        'role',
    ];

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'component_equipment_id');
    }
}
