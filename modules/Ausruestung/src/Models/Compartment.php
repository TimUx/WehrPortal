<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Geräteraum of a vehicle, or a shelf of a room */
class Compartment extends Model
{
    protected $table = 'ar_compartments';

    protected $fillable = ['location_id', 'name', 'sort_order'];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function loadPlanItems(): HasMany
    {
        return $this->hasMany(LoadPlanItem::class, 'compartment_id');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'compartment_id');
    }

    public function label(): string
    {
        return $this->location?->name.' › '.$this->name;
    }
}
