<?php

namespace Modules\Fahrtenbuch\src\Models;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A fuel stop with Kilometerstand, litres and cost; "voll getankt" makes the consumption countable */
class Fueling extends Model
{
    public const FUELS = ['diesel' => 'Diesel', 'benzin' => 'Benzin', 'adblue' => 'AdBlue', 'sonstiges' => 'Sonstiges'];

    protected $table = 'ft_fuelings';

    protected $fillable = ['vehicle_id', 'user_id', 'fueled_at', 'km', 'liters', 'cost_cents', 'fuel', 'full_tank', 'station', 'notes', 'receipt', 'receipt_mime'];

    protected $hidden = ['receipt'];

    protected function casts(): array
    {
        return ['fueled_at' => 'datetime', 'km' => 'integer', 'liters' => 'float', 'cost_cents' => 'integer', 'full_tank' => 'boolean'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fuelLabel(): string
    {
        return self::FUELS[$this->fuel] ?? $this->fuel;
    }

    public function cost(): ?string
    {
        return $this->cost_cents !== null ? Money::format($this->cost_cents) : null;
    }

    public function liters(): string
    {
        return number_format($this->liters, 2, ',', '.').' l';
    }
}
