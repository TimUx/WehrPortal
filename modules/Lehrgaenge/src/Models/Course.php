<?php

namespace Modules\Lehrgaenge\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A Lehrgang of the Kreis without fixed date, read daily from the Lehrgangsportal (fox112), or added by
 * hand. On the Standardliste it stays offered while fox112 doesn't list it.
 */
class Course extends Model
{
    protected $table = 'lq_courses';

    protected $attributes = ['listed' => false, 'is_standard' => false];

    protected $fillable = ['name', 'titles', 'listed', 'is_standard', 'last_seen_at'];

    protected function casts(): array
    {
        return ['titles' => 'array', 'listed' => 'boolean', 'is_standard' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class, 'course_id');
    }

    /** What a Kamerad can report Interesse for: listed in the portal at the last import, or on the Standardliste */
    public function scopeOffered(Builder $query): void
    {
        $query->where(fn ($q) => $q->where('listed', true)->orWhere('is_standard', true));
    }

    public function isOffered(): bool
    {
        return $this->listed || $this->is_standard;
    }

    /** Variants in the portal, e.g. "(Wochenendlehrgang)", "(Wochenlehrgang)" */
    public function variants(): array
    {
        return collect($this->titles ?? [])->map(fn (string $title) => trim(Str::after($title, $this->name)))->filter()->unique()->values()->all();
    }
}
