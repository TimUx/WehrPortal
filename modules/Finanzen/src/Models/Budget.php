<?php

namespace Modules\Finanzen\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The amount planned for one Kostenstelle in one year */
class Budget extends Model
{
    protected $table = 'fin_budgets';

    protected $fillable = ['cost_center_id', 'year', 'location_id', 'amount_cents', 'notes'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'amount_cents' => 'integer'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
