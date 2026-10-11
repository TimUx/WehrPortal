<?php

namespace Modules\Fahrtenbuch\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Einsatzbericht\src\Models\Report;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * A trip in the Fahrtenbuch: started (open) until it is ended with the Kilometerstand. Never
 * deleted; a correction leaves a revision, a wrong trip is cancelled with a reason.
 */
class Trip extends Model
{
    public const PURPOSES = [
        'einsatz' => 'Einsatz', 'uebung' => 'Übung / Ausbildung', 'dienstfahrt' => 'Dienstfahrt', 'werkstatt' => 'Werkstatt / Prüfung',
        'jugend' => 'Jugendfeuerwehr', 'veranstaltung' => 'Veranstaltung', 'sonstiges' => 'Sonstiges',
    ];

    protected $table = 'ft_trips';

    protected $fillable = ['vehicle_id', 'driver_id', 'started_at', 'ended_at', 'start_km', 'end_km', 'origin', 'destination', 'purpose', 'purpose_note',
        'report_id', 'booking_id', 'passengers', 'notes', 'created_by', 'reminded_at', 'voided_at', 'void_reason'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'reminded_at' => 'datetime', 'voided_at' => 'datetime',
            'start_km' => 'integer', 'end_km' => 'integer', 'passengers' => 'integer'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class)->latest('created_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at')->whereNull('voided_at');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('voided_at');
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->voided_at === null;
    }

    public function distance(): ?int
    {
        return $this->end_km !== null ? $this->end_km - $this->start_km : null;
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? $this->purpose;
    }
}
