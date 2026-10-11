<?php

namespace Modules\Fahrzeuge\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ausruestung\src\Models\Location;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * The Fahrzeugprofil of a vehicle from the shared list: Funkrufname, Kennzeichen, type, technical
 * data, Ausstattung, photo, Kilometerstand and status. "Außer Dienst" is the vehicle's is_active.
 */
class Profile extends Model
{
    public const STATUSES = ['ready' => 'Einsatzbereit', 'limited' => 'Bedingt einsatzbereit', 'unavailable' => 'Nicht einsatzbereit'];

    public const CATEGORIES = [
        'loesch' => 'Löschfahrzeug', 'hubrettung' => 'Hubrettungsfahrzeug', 'ruest' => 'Rüst- und Gerätewagen',
        'fuehrung' => 'Einsatzleitwagen / Kommando', 'transport' => 'Mannschaftstransport', 'logistik' => 'Logistik / Wechsellader',
        'anhaenger' => 'Anhänger', 'sonstige' => 'Sonstiges',
    ];

    protected $table = 'fv_profiles';

    protected $fillable = ['vehicle_id', 'call_sign', 'license_plate', 'category', 'vehicle_type', 'chassis', 'body_builder', 'year_built',
        'first_registration', 'vin', 'gross_weight_kg', 'crew', 'license_class', 'water_liters', 'foam_liters', 'pump', 'features', 'notes',
        'photo', 'photo_updated_at', 'mileage_km', 'operating_hours', 'mileage_on', 'status', 'status_note', 'status_changed_at', 'location_id'];

    protected $hidden = ['photo'];

    protected $attributes = ['status' => 'ready'];

    protected function casts(): array
    {
        return ['features' => 'array', 'first_registration' => 'date', 'mileage_on' => 'date', 'photo_updated_at' => 'datetime',
            'status_changed_at' => 'datetime', 'year_built' => 'integer', 'gross_weight_kg' => 'integer', 'water_liters' => 'integer',
            'foam_liters' => 'integer', 'mileage_km' => 'integer', 'operating_hours' => 'float'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function categoryLabel(): ?string
    {
        return $this->category ? self::CATEGORIES[$this->category] ?? $this->category : null;
    }
}
