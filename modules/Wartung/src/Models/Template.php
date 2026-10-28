<?php

namespace Modules\Wartung\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Ausruestung\src\Models\Category;
use Modules\Ausruestung\src\Models\Equipment;

/**
 * A Wartungsvorlage: what is checked (checklist), how often (interval) and how early it shows up
 * (Voranzeige), for all devices of a category and/or single devices, done in house or by a Fachfirma.
 */
class Template extends Model
{
    public const PERFORMERS = ['internal' => 'Eigene Prüfer', 'external' => 'Fachfirma'];

    protected $table = 'wm_templates';

    protected $fillable = ['name', 'description', 'category_id', 'interval_months', 'lead_days', 'checklist', 'counts_as_inspection',
        'performer', 'provider_id', 'required_qualification', 'estimated_minutes', 'is_active'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'counts_as_inspection' => 'boolean', 'is_active' => 'boolean',
            'interval_months' => 'integer', 'lead_days' => 'integer', 'estimated_minutes' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** Devices picked one by one, besides those of the category */
    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'wm_template_equipment', 'template_id', 'equipment_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return Builder<Equipment> All devices in service this template applies to. */
    public function devices(): Builder
    {
        $picked = $this->equipment()->pluck('ar_equipment.id');

        return Equipment::query()->inService()
            ->where(fn ($q) => $q->whereIn('id', $picked)->when($this->category_id, fn ($q) => $q->orWhere('category_id', $this->category_id)));
    }

    public function intervalLabel(): string
    {
        return match (true) {
            $this->interval_months === null => 'nur bei Bedarf',
            $this->interval_months % 12 === 0 => $this->interval_months === 12 ? 'jährlich' : 'alle '.($this->interval_months / 12).' Jahre',
            default => $this->interval_months === 1 ? 'monatlich' : 'alle '.$this->interval_months.' Monate',
        };
    }
}
