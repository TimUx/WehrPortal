<?php

namespace Modules\Gefahrstoffe\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $table = 'gf_materials';

    protected $fillable = [
        'un_number', 'name', 'synonyms', 'description', 'adr_class', 'packing_group', 'hazard_number',
        'hazard_labels', 'ghs_pictograms', 'dangers', 'first_aid', 'firefighting', 'spillage',
        'source_name', 'source_version', 'source_reference', 'imported_by', 'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'hazard_labels' => 'array',
            'ghs_pictograms' => 'array',
            'imported_by' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        $digits = preg_replace('/\D/', '', $term);

        return $query->where(function (Builder $query) use ($term, $digits) {
            $query->where('name', 'ilike', '%'.$term.'%')
                ->orWhere('synonyms', 'ilike', '%'.$term.'%')
                ->orWhere('description', 'ilike', '%'.$term.'%');

            if ($digits !== '' && strlen($digits) <= 4) {
                $query->orWhere('un_number', 'like', '%'.$digits.'%');
            }
        });
    }

    /** @return array<string, mixed> */
    public function catalogueEntry(): array
    {
        return $this->only([
            'un_number', 'name', 'synonyms', 'description', 'adr_class', 'packing_group', 'hazard_number',
            'hazard_labels', 'ghs_pictograms', 'dangers', 'first_aid', 'firefighting', 'spillage',
            'source_name', 'source_version', 'source_reference', 'imported_at',
        ]);
    }
}
