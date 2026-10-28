<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Extensions\Locations\Models\Location as Standort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Modules\Ausruestung\src\Services\EquipmentService;

/** A finished Inventur of one location: what was expected, found, missing, moved here or unknown */
class Stocktake extends Model
{
    protected $table = 'ar_stocktakes';

    protected $fillable = ['location_id', 'ext_location_id', 'location_name', 'user_id', 'expected', 'found', 'missing', 'moved', 'unknown', 'notes', 'finished_at'];

    protected function casts(): array
    {
        return [
            'found' => 'array', 'missing' => 'array', 'moved' => 'array', 'unknown' => 'array',
            'finished_at' => 'datetime', 'expected' => 'integer', 'ext_location_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('equipment-manager-location', function (Builder $query): void {
            if (! config('locations.enabled') || ! Auth::hasUser()) {
                return;
            }

            $user = Auth::user();
            if (! $user instanceof User || ! EquipmentService::canManage($user)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $locationIds = EquipmentService::equipmentManagementScope($user);
            if ($locationIds !== null) {
                $query->whereIn('ext_location_id', $locationIds);
            }
        });
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function standort(): BelongsTo
    {
        return $this->belongsTo(Standort::class, 'ext_location_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
