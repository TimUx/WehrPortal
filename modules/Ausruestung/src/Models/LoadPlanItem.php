<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Soll-Beladung: this many items of a category belong into this compartment */
class LoadPlanItem extends Model
{
    protected $table = 'ar_load_plan_items';

    protected $fillable = ['compartment_id', 'category_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function compartment(): BelongsTo
    {
        return $this->belongsTo(Compartment::class, 'compartment_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
