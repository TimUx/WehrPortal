<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    public const ATEMSCHUTZ_CATEGORIES = [
        'Pressluftatmer',
        'Atemschutzmaske',
        'Druckluftflasche (Composite)',
        'Druckluftflasche (Stahl)',
        'Chemikalienschutzanzug',
    ];

    protected $table = 'ar_categories';

    protected $fillable = ['name', 'lifetime_years', 'inspection_interval_months', 'lifetime_basis', 'sort_order'];

    protected function casts(): array
    {
        return ['lifetime_years' => 'integer', 'inspection_interval_months' => 'integer', 'sort_order' => 'integer'];
    }

    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class, 'category_id');
    }

    public function scopeForGeneralUse(Builder $query): Builder
    {
        foreach (['atemschutz', 'pressluftatmer', 'druckluftflasche', 'chemikalienschutzanzug'] as $term) {
            $query->whereRaw('LOWER(name) NOT LIKE ?', ['%'.$term.'%']);
        }

        return $query;
    }

    public static function isAtemschutzCategoryName(?string $name): bool
    {
        $name = mb_strtolower(trim((string) $name));

        return $name !== ''
            && collect(['atemschutz', 'pressluftatmer', 'druckluftflasche', 'chemikalienschutzanzug'])
                ->contains(fn (string $term) => str_contains($name, $term));
    }

    public function isAtemschutzCategory(): bool
    {
        return self::isAtemschutzCategoryName($this->name);
    }
}
