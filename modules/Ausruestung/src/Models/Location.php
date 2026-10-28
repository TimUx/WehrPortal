<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Ausruestung\src\Services\EquipmentService;

class Location extends Model
{
    public const ROOM = 'room';

    public const VEHICLE = 'vehicle';

    public const KINDS = [self::ROOM => 'Raum / Lager', self::VEHICLE => 'Fahrzeug'];

    protected $table = 'ar_locations';

    protected $fillable = ['name', 'kind', 'description', 'sort_order', 'ext_location_id'];

    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            if (! config('locations.enabled') || ! Auth::hasUser()) {
                return;
            }

            $user = Auth::user();
            $standortId = $location->getAttribute('ext_location_id');
            if (! $user instanceof User || ! $standortId || ! EquipmentService::canManageLocation($user, (int) $standortId)) {
                throw new AuthorizationException('Bitte einen Standort auswählen, den du verwalten darfst.');
            }

            if (! $location->exists || ! $location->isDirty('ext_location_id')) {
                return;
            }

            $previousStandortId = $location->getRawOriginal('ext_location_id');
            if ($previousStandortId !== null && ! EquipmentService::canManageLocation($user, (int) $previousStandortId)) {
                throw new AuthorizationException('Du darfst diesen Standort nicht verwalten.');
            }
            if (Schema::hasTable('ar_equipment') && Equipment::withoutGlobalScopes()->where('location_id', $location->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'ext_location_id' => 'Verschiebe zuerst alle Geräte einzeln aus diesem Lagerort, bevor du den Standort änderst.',
                ]);
            }
        });
    }

    public function standort(): BelongsTo
    {
        return $this->belongsTo(\Extensions\Locations\Models\Location::class, 'ext_location_id');
    }

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
