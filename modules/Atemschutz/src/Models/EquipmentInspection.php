<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentInspection extends Model
{
    protected $table = 'as_equipment_inspections';

    protected $fillable = [
        'equipment_id',
        'performed_on',
        'next_due_on',
        'result',
        'checklist',
        'findings',
        'inspector_name',
        'qualification',
        'inspector_number',
        'provider_name',
        'signature',
        'signed_at',
        'content_hash',
        'inspector_user_id',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'performed_on' => 'date',
            'next_due_on' => 'date',
            'checklist' => 'array',
            'signed_at' => 'immutable_datetime',
        ];
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }
}
