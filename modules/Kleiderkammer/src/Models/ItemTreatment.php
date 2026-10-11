<?php

namespace Modules\Kleiderkammer\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Kleiderkammer\src\Enums\TreatmentType;

class ItemTreatment extends Model
{
    protected $table = 'kk_item_treatments';

    protected $fillable = [
        'item_id',
        'type',
        'treated_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => TreatmentType::class,
            'treated_at' => 'date',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }
}
