<?php

namespace Modules\Atemschutz\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A warning of a Trupp the first time it came up, kept for the protocol */
class Warning extends Model
{
    public $timestamps = false;

    protected $table = 'as_warnings';

    protected $fillable = ['team_id', 'kind', 'message', 'raised_at', 'acknowledged_at', 'acknowledged_by'];

    protected function casts(): array
    {
        return ['raised_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
