<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Recall extends Model
{
    protected $table = 'ar_recalls';

    protected $fillable = [
        'title', 'manufacturer', 'model', 'serial_from', 'serial_to', 'batch_number',
        'manufactured_from', 'manufactured_to', 'description', 'url', 'issued_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_from' => 'date',
            'manufactured_to' => 'date',
            'issued_at' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'ar_recall_equipment', 'recall_id', 'equipment_id')
            ->withPivot(['previous_status', 'resolved_at', 'resolution'])
            ->withTimestamps();
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
