<?php

namespace Modules\Wasserkarte\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One status change of a source, for the history */
class StatusEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'wk_status_events';

    protected $fillable = ['source_id', 'status', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
