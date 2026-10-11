<?php

namespace Modules\Fahrzeuge\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A recurring date of a vehicle: HU, Sicherheitsprüfung, UVV-Prüfung, Pumpenprüfung, Inspektion … */
class Deadline extends Model
{
    /** Suggestions in the form; interval in months */
    public const PRESETS = [
        'Hauptuntersuchung (HU)' => 12, 'Sicherheitsprüfung (SP)' => 6, 'UVV-Prüfung (DGUV Grundsatz 314-003)' => 12,
        'Pumpenprüfung (DIN 14420)' => 12, 'Inspektion laut Hersteller' => 12, 'Prüfung Hubrettungsfahrzeug' => 12,
        'Prüfung Ladebordwand / Kran' => 12, 'Prüfung Feuerlöscher an Bord' => 24, 'Fahrtenschreiber' => 24,
    ];

    protected $table = 'fv_deadlines';

    protected $fillable = ['vehicle_id', 'title', 'interval_months', 'due_on', 'lead_days', 'notes', 'due_mailed_at', 'overdue_mailed_at', 'is_active'];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'interval_months' => 'integer', 'lead_days' => 'integer', 'is_active' => 'boolean',
            'due_mailed_at' => 'datetime', 'overdue_mailed_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class)->latest('done_on');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isOverdue(): bool
    {
        return $this->due_on->lt(now('Europe/Berlin')->startOfDay());
    }

    public function isDue(): bool
    {
        return $this->due_on->lte(now('Europe/Berlin')->startOfDay()->addDays($this->lead_days));
    }
}
