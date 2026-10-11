<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    public const ROOM = 'room';

    public const VEHICLE = 'vehicle';

    public const KINDS = [self::ROOM => 'Raum / Lager', self::VEHICLE => 'Fahrzeug'];

    protected $table = 'ar_locations';

    protected $fillable = ['name', 'kind', 'description', 'sort_order'];

    public function compartments(): HasMany
    {
        return $this->hasMany(Compartment::class, 'location_id')->orderBy('sort_order')->orderBy('name');
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'location_id');
    }

    public function isVehicle(): bool
    {
        return $this->kind === self::VEHICLE;
    }
}
