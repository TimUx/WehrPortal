<?php

namespace Modules\Maengelmanagement\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ausruestung\src\Models\Equipment;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;
use Modules\Maengelmanagement\src\Enums\DefectStatus;
use Modules\Maengelmanagement\src\Enums\Urgency;

class Defect extends Model
{
    protected $table = 'mm_defects';

    protected $fillable = [
        'device_id', 'vehicle_id', 'location', 'title', 'description', 'urgency', 'status',
        'reporter_id', 'assigned_to', 'resolved_at', 'question_open_at', 'equipment_id', 'ar_damage_id', 'reminded_at',
    ];

    /** How long the reporter may say "doch nicht behoben" after it was closed */
    public const REOPEN_DAYS = 30;

    protected function casts(): array
    {
        return [
            'urgency' => Urgency::class,
            'status' => DefectStatus::class,
            'resolved_at' => 'datetime',
            'question_open_at' => 'datetime',
            'reminded_at' => 'datetime',
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

    /** A Gerät of the Ausrüstungsverwaltung (only while that module is used) */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** The photos of the report itself; those of comments hang on their event */
    public function photos(): HasMany
    {
        return $this->hasMany(DefectPhoto::class, 'defect_id')->whereNull('event_id');
    }

    /** The reporter may reopen it shortly after it was closed */
    public function canReopen(User $user): bool
    {
        return $user->id === $this->reporter_id && $this->status === DefectStatus::Resolved
            && $this->resolved_at !== null && $this->resolved_at->gt(now()->subDays(self::REOPEN_DAYS));
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
        return $this->device?->label() ?? $this->vehicle?->name
            ?? ($this->equipment ? $this->equipment->name.' ('.$this->equipment->code.')' : null) ?? ($this->location ?: 'Ohne Zuordnung');
    }
}
