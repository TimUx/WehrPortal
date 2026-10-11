<?php

namespace Modules\Finanzen\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Kostenstelle, e.g. "Jugendfeuerwehr" or "Fahrzeuge", with a budget per year */
class CostCenter extends Model
{
    protected $table = 'fin_cost_centers';

    protected $fillable = ['name', 'code', 'description', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name');
    }

    public function label(): string
    {
        return $this->code ? $this->code.' – '.$this->name : $this->name;
    }
}
