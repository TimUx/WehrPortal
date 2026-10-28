<?php

namespace Modules\Fahrzeugbuchung\src\Models;

use Database\Factories\VehicleFactory;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $ext_location_id
 */
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return VehicleFactory::new();
    }

    protected $fillable = [
        'name',
        'short_name',
        'type',
        'color',
        'description',
        'is_active',
        'sort_order',
        'ext_location_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** The Standort (Locations extension) */
    public function standort(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function displayName(): string
    {
        return $this->short_name ?? $this->name;
    }
}
