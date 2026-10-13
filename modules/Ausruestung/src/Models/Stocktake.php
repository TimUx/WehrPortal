<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A finished Inventur of one location: what was expected, found, missing, moved here or unknown */
class Stocktake extends Model
{
    protected $table = 'ar_stocktakes';

    protected $fillable = ['location_id', 'location_name', 'user_id', 'expected', 'found', 'missing', 'moved', 'unknown', 'notes', 'finished_at'];

    protected function casts(): array
    {
        return ['found' => 'array', 'missing' => 'array', 'moved' => 'array', 'unknown' => 'array', 'finished_at' => 'datetime', 'expected' => 'integer'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
