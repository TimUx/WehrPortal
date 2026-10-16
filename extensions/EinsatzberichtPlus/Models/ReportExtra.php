<?php

namespace Extensions\EinsatzberichtPlus\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Einsatzbericht\src\Models\Report;

/** Kostenpflichtiger Einsatz: whether the Einsatz is chargeable, to whom, and why */
class ReportExtra extends Model
{
    protected $table = 'x_ebp_extras';

    protected $attributes = ['chargeable' => false];

    protected $fillable = ['report_id', 'chargeable', 'chargeable_to', 'chargeable_note'];

    protected function casts(): array
    {
        return ['chargeable' => 'boolean', 'chargeable_note' => 'encrypted'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class, 'report_id');
    }
}
