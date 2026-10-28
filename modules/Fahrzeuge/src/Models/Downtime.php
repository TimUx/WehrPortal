<?php

namespace Modules\Fahrzeuge\src\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** Werkstatt or another time the vehicle can't be used; blocks bookings in the Fahrzeugbuchung */
class Downtime extends Model
{
    protected $table = 'fv_downtimes';

    protected $fillable = ['vehicle_id', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Overlapping the half-open period [start, end) */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $start));
    }

    public function isCurrent(): bool
    {
        return $this->starts_at->lte(now()) && ($this->ends_at === null || $this->ends_at->gt(now()));
    }

    public function periodLabel(): string
    {
        $f = fn (Carbon $t) => $t->copy()->setTimezone('Europe/Berlin')->format('d.m.Y H:i');

        return $f($this->starts_at).' – '.($this->ends_at ? $f($this->ends_at) : 'bis auf Weiteres');
    }
}
