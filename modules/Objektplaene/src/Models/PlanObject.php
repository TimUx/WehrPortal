<?php

namespace Modules\Objektplaene\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Objektplaene\src\Services\ObjectPlanService;

/** An object with Feuerwehrplan and Brandschutzunterlagen: a school, a factory, a care home … */
class PlanObject extends Model
{
    public const TYPES = [
        'wohnen' => 'Wohngebäude', 'schule' => 'Schule / Kita', 'pflege' => 'Krankenhaus / Pflege', 'versammlung' => 'Versammlungsstätte',
        'industrie' => 'Industrie / Gewerbe', 'lager' => 'Lager / Logistik', 'landwirtschaft' => 'Landwirtschaft', 'oeffentlich' => 'Öffentliches Gebäude',
        'verkehr' => 'Verkehrsanlage', 'sonstiges' => 'Sonstiges',
    ];

    /** Gefährdungsklasse, from what the Einsatzleiter must expect */
    public const HAZARD_CLASSES = ['normal' => 'Normal', 'erhoeht' => 'Erhöht', 'hoch' => 'Hoch'];

    public const HAZARD_HINTS = [
        'normal' => 'übliche Brandlast, keine besonderen Gefahren',
        'erhoeht' => 'z. B. viele Personen, eingeschränkt bewegliche Personen, größere Brandlast',
        'hoch' => 'z. B. Gefahrstoffe, Explosionsgefahr, große Brandabschnitte',
    ];

    /** Map and badge colours: green, amber, red */
    public const COLORS = ['normal' => '#16a34a', 'erhoeht' => '#d97706', 'hoch' => '#dc2626'];

    public const BADGES = ['normal' => 'bg-green-100 text-green-800', 'erhoeht' => 'bg-amber-100 text-amber-800', 'hoch' => 'bg-red-100 text-red-800'];

    protected $table = 'op_objects';

    protected $fillable = ['name', 'object_type', 'hazard_class', 'address', 'latitude', 'longitude', 'radius_m', 'has_bma', 'has_fsd', 'has_hazardous_materials',
        'access', 'hazards', 'contacts', 'notes', 'review_interval_months', 'last_reviewed_on', 'next_review_on', 'due_mailed_at', 'is_active'];

    protected $attributes = ['hazard_class' => 'normal', 'radius_m' => 150];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer', 'has_bma' => 'boolean', 'has_fsd' => 'boolean',
            'has_hazardous_materials' => 'boolean', 'review_interval_months' => 'integer', 'last_reviewed_on' => 'date', 'next_review_on' => 'date',
            'due_mailed_at' => 'datetime', 'is_active' => 'boolean'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'object_id')->orderBy('sort_order')->orderBy('id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'object_id')->latest('reviewed_on')->latest('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->object_type] ?? $this->object_type;
    }

    public function hazardLabel(): string
    {
        return 'Gefährdung '.mb_strtolower(self::HAZARD_CLASSES[$this->hazard_class] ?? $this->hazard_class);
    }

    public function hazardBadge(): string
    {
        return self::BADGES[$this->hazard_class] ?? 'bg-gray-100 text-gray-700';
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** The review is due (today or earlier) */
    public function reviewDue(): bool
    {
        return $this->next_review_on !== null && $this->next_review_on->lte(ObjectPlanService::today());
    }

    /** "BMA · FSD · Gefahrstoffe" */
    public function features(): array
    {
        return array_keys(array_filter(['Brandmeldeanlage' => $this->has_bma, 'Schlüsseldepot (FSD)' => $this->has_fsd, 'Gefahrstoffe' => $this->has_hazardous_materials]));
    }

    public function navigationUrl(): ?string
    {
        return $this->hasPosition() ? 'https://www.google.com/maps/dir/?api=1&destination='.$this->latitude.','.$this->longitude : null;
    }
}
