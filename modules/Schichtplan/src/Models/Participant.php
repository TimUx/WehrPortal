<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Participant extends Model
{
    protected $table = 'sp_participants';

    protected $fillable = ['plan_id', 'user_id', 'available_from', 'available_until'];

    protected function casts(): array
    {
        return ['available_from' => 'datetime', 'available_until' => 'datetime'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Is there for the whole block */
    public function isAvailableFor(Slot $slot): bool
    {
        return ($this->available_from === null || $this->available_from->lte($slot->starts_at))
            && ($this->available_until === null || $this->available_until->gte($slot->ends_at));
    }
}
