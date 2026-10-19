<?php

namespace Extensions\Hydrantenkontrolle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hydrant extends Model
{
    protected $table = 'x_hc_hydrants';

    protected $fillable = ['ext_location_id', 'city', 'number', 'street', 'house_number', 'location_description', 'latitude', 'longitude', 'active', 'version'];

    protected $attributes = ['active' => true, 'version' => 1];

    protected function casts(): array
    {
        return ['ext_location_id' => 'integer', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }
}
