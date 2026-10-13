<?php

namespace Modules\Expositionen\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who proposed, confirmed, changed or reported an exposure, and when */
class ExposureEvent extends Model
{
    public const ACTIONS = ['proposed' => 'vorgeschlagen', 'recorded' => 'erfasst', 'confirmed' => 'bestätigt', 'changed' => 'geändert', 'reported' => 'an die ZED gemeldet'];

    public const UPDATED_AT = null;

    protected $table = 'ex_events';

    protected $fillable = ['exposure_id', 'user_id', 'action', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
