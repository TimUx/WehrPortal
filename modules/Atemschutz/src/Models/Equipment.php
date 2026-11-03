<?php

namespace Modules\Atemschutz\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Equipment extends Model
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_REPAIR = 'in_repair';

    public const STATUS_DEFECTIVE = 'defective';

    public const STATUS_RETIRED = 'retired';

    protected $table = 'as_equipment';

    protected $fillable = [
        'code',
        'device_number',
        'name',
        'type_id',
        'manufacturer',
        'model',
        'serial_number',
        'manufactured_at',
        'commissioned_at',
        'purchased_at',
        'status',
        'fill_pressure_bar',
        'last_filled_on',
        'next_inspection_at',
        'inspection_basis',
        'specifications',
        'ext_location_id',
        'physical_location',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'manufactured_at' => 'date',
            'commissioned_at' => 'date',
            'purchased_at' => 'date',
            'last_filled_on' => 'date',
            'next_inspection_at' => 'date',
            'fill_pressure_bar' => 'integer',
            'specifications' => 'array',
        ];
    }

    /** @return BelongsTo<EquipmentType, $this> */
    public function type(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class, 'type_id');
    }

    /** @return HasMany<EquipmentComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(EquipmentComponent::class, 'equipment_id');
    }

    /** @return HasOne<EquipmentComponent, $this> */
    public function componentOf(): HasOne
    {
        return $this->hasOne(EquipmentComponent::class, 'component_equipment_id');
    }

    /** @return HasMany<EquipmentInspection, $this> */
    public function inspections(): HasMany
    {
        return $this->hasMany(EquipmentInspection::class, 'equipment_id');
    }

    /** @return HasMany<EquipmentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EquipmentEvent::class, 'equipment_id');
    }

    /** @return HasMany<EquipmentTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(EquipmentTask::class, 'equipment_id');
    }

    /** @return HasMany<EquipmentDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EquipmentDocument::class, 'equipment_id');
    }

    /** @return HasMany<TagAssignment, $this> */
    public function tagAssignments(): HasMany
    {
        return $this->hasMany(TagAssignment::class)->orderByDesc('assigned_at');
    }

    public function isOperationallyReady(): bool
    {
        if ($this->status !== self::STATUS_AVAILABLE || ! $this->type?->is_active) {
            return false;
        }

        $nextInspection = $this->getAttribute('next_inspection_at');
        if ($nextInspection !== null && Carbon::parse($nextInspection)->isBefore(today())) {
            return false;
        }

        if (! $this->type->isCylinder()) {
            return true;
        }

        $minimum = $this->type->minimum_fill_pressure_bar;

        return $minimum !== null
            && $this->fill_pressure_bar !== null
            && $this->fill_pressure_bar > 0
            && $this->fill_pressure_bar >= $minimum;
    }
}
