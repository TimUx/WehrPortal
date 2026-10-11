<?php

namespace Modules\Ausruestung\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
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
}
