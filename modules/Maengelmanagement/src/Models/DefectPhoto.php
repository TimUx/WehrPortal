<?php

namespace Modules\Maengelmanagement\src\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefectPhoto extends Model
{
    protected $table = 'mm_defect_photos';

    protected $fillable = ['defect_id', 'event_id', 'mime', 'data'];

    protected $hidden = ['data'];

    public function defect(): BelongsTo
    {
        return $this->belongsTo(Defect::class, 'defect_id');
    }
}
