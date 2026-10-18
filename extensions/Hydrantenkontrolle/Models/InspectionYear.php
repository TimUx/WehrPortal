<?php

namespace Extensions\Hydrantenkontrolle\Models;

use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InspectionYear extends Model
{
    protected $table = 'x_hc_years';

    protected $fillable = ['ext_location_id', 'year'];

    protected function casts(): array
    {
        return ['ext_location_id' => 'integer', 'year' => 'integer'];
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }
}
