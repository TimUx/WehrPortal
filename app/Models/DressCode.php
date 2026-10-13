<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Kleidung that can be asked for, e.g. "Einsatzkleidung" or "Dienstuniform" */
class DressCode extends Model
{
    protected $fillable = ['name', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return Collection<int, string> id => name, in the configured order */
    public static function options()
    {
        return self::orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
    }

    /** The names of the chosen ones, in the configured order; deleted ones are left out */
    public static function names(?array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids ?? []));

        return $ids === [] ? [] : self::whereIn('id', $ids)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();
    }
}
