<?php

namespace Modules\Wasserkarte\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A Prüfung of a source with measured flow and pressures */
class Inspection extends Model
{
    public const RESULTS = ['passed' => 'In Ordnung', 'defect' => 'Mangel'];

    protected $table = 'wk_inspections';

    protected $fillable = ['source_id', 'inspected_on', 'result', 'flow_lpm', 'static_pressure_bar', 'flow_pressure_bar', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['inspected_on' => 'date', 'flow_lpm' => 'integer', 'static_pressure_bar' => 'float', 'flow_pressure_bar' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
