<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentTask extends Model
{
    protected $table = 'as_equipment_tasks';

    protected $fillable = [
        'equipment_id',
        'title',
        'due_on',
        'status',
        'notes',
        'created_by',
        'inspection_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /** @return BelongsTo<EquipmentInspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(EquipmentInspection::class, 'inspection_id');
    }
}
