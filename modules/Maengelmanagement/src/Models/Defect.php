<?php

namespace Modules\Maengelmanagement\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;

class Defect extends Model
{
    protected $table = 'mm_defects';

    protected $fillable = [
        'device_id', 'vehicle_id', 'location', 'title', 'description', 'urgency', 'status',
        'reporter_id', 'assigned_to', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'urgency' => Urgency::class,
            'status' => DefectStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DefectPhoto::class, 'defect_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DefectEvent::class, 'defect_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [DefectStatus::Open->value, DefectStatus::InProgress->value]);
    }

    /** What the defect is about: the device, the vehicle, or the free-text place */
    public function subject(): string
    {
        return $this->device?->label() ?? $this->vehicle?->name ?? ($this->location ?: 'Ohne Zuordnung');
    }
}
