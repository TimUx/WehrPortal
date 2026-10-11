<?php

namespace Modules\Fahrtenbuch\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A correction of a finished trip: who, why, and the fields before and after */
class Revision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'ft_revisions';

    protected $fillable = ['trip_id', 'user_id', 'reason', 'before', 'after'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
