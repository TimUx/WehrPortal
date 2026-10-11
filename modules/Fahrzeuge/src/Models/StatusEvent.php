<?php

namespace Modules\Fahrzeuge\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One change of a vehicle's status, for the history */
class StatusEvent extends Model
{
    public const UPDATED_AT = null;

    public const LABELS = [...Profile::STATUSES, 'decommissioned' => 'Außer Dienst gestellt', 'recommissioned' => 'Wieder in Dienst gestellt'];

    protected $table = 'fv_status_events';

    protected $fillable = ['vehicle_id', 'status', 'note', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }
}
