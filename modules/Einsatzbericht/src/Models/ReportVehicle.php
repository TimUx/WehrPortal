<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A vehicle on the Einsatz with its times; or the Bereitschaft at the Gerätehaus */
class ReportVehicle extends Model
{
    public const TIMES = ['departed_at' => 'Ausgerückt', 'arrived_at' => 'An Einsatzstelle', 'left_at' => 'Ab Einsatzstelle', 'returned_at' => 'Wieder im Gerätehaus'];

    protected $table = 'eb_report_vehicles';

    protected $attributes = ['is_standby' => false];

    protected $fillable = ['report_id', 'vehicle_id', 'name', 'is_standby', 'departed_at', 'arrived_at', 'left_at', 'returned_at', 'sort_order'];

    protected function casts(): array
    {
        return ['is_standby' => 'boolean', 'departed_at' => 'datetime', 'arrived_at' => 'datetime', 'left_at' => 'datetime', 'returned_at' => 'datetime'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function crew(): HasMany
    {
        return $this->hasMany(CrewMember::class, 'report_vehicle_id');
    }
}
