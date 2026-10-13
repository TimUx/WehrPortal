<?php

namespace Modules\Lehrgaenge\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A Lehrgang of the Kreis without fixed date, read daily from the Lehrgangsportal (fox112), or added by
 * hand. Offered to the Kameraden only when the Ausbildungsleiter chose so (is_standard); fox112 just suggests.
 */
class Course extends Model
{
    protected $table = 'lq_courses';

    protected $attributes = ['listed' => false, 'is_standard' => false];

    protected $fillable = ['name', 'titles', 'listed', 'is_standard', 'last_seen_at', 'repeat_months', 'suggested_at', 'ignored_at', 'removed_at', 'gone_since'];

    protected function casts(): array
    {
        return ['titles' => 'array', 'listed' => 'boolean', 'is_standard' => 'boolean', 'last_seen_at' => 'datetime', 'repeat_months' => 'integer', 'suggested_at' => 'datetime', 'ignored_at' => 'datetime', 'removed_at' => 'datetime', 'gone_since' => 'datetime'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class, 'course_id');
    }

    /** What a Kamerad can report Interesse for: what the Ausbildungsleiter chose to offer (is_standard) and didn't take off the list */
    public function scopeOffered(Builder $query): void
    {
        $query->where('is_standard', true)->whereNull('removed_at');
    }

    public function isOffered(): bool
    {
        return $this->is_standard && $this->removed_at === null;
    }

    /** On the Lehrgangsliste: everything not taken off it */
    public function scopeOnList(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    /** Variants in the portal, e.g. "(Wochenendlehrgang)", "(Wochenlehrgang)" */
    public function variants(): array
    {
        return collect($this->titles ?? [])->map(fn (string $title) => trim(Str::after($title, $this->name)))->filter()->unique()->values()->all();
    }
}
