<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Assignment extends Model
{
    protected $table = 'sp_assignments';

    protected $fillable = ['plan_id', 'slot_id', 'station_id', 'user_id', 'locked'];

    protected function casts(): array
    {
        return ['locked' => 'boolean'];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class, 'slot_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class, 'station_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
