<?php

namespace Modules\Personalverwaltung\src\Models;

use Illuminate\Database\Eloquent\Model;

/** A kind of Ehrung, e.g. "Brandschutzehrenzeichen 25 Jahre"; with Dienstjahre it is suggested when due */
class HonorType extends Model
{
    protected $table = 'pm_honor_types';

    protected $fillable = ['name', 'after_years', 'sort_order'];

    protected function casts(): array
    {
        return ['after_years' => 'integer', 'sort_order' => 'integer'];
    }
}
