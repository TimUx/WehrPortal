<?php

namespace Modules\Einsatzbericht\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A Verbrauchsmaterial of the catalog, with its fixed unit */
class Material extends Model
{
    public const CATEGORIES = [
        'loeschmittel' => 'Löschmittel',
        'bindemittel' => 'Bindemittel',
        'sanitaetsmaterial' => 'Sanitätsmaterial',
        'betriebsstoffe' => 'Betriebsstoffe',
        'sonstiges' => 'Sonstiges',
    ];

    public const UNITS = [
        'liter' => 'Liter',
        'kilogramm' => 'Kilogramm',
        'sack' => 'Sack',
        'stueck' => 'Stück',
        'meter' => 'Meter',
    ];

    protected $table = 'eb_materials';

    protected $attributes = ['is_active' => true, 'category' => 'sonstiges', 'unit' => 'stueck'];

    protected $fillable = ['name', 'category', 'unit', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function label(): string
    {
        return $this->name.' ('.(self::UNITS[$this->unit] ?? $this->unit).')';
    }
}
