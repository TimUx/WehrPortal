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
        'Monatliche Fahrzeugkontrolle' => 1,
    ];

    /** Prüfschritte suggested with a preset; each can be changed per vehicle */
    public const STEPS = [
        'UVV-Prüfung (DGUV Grundsatz 314-003)' => ['Bremsanlage und Feststellbremse', 'Lenkung und Achsen', 'Reifen und Räder', 'Beleuchtung und Sondersignalanlage',
            'Aufbau, Geräteräume und Rollläden', 'Auftritte, Haltegriffe und Leitern', 'Sicherung der Beladung', 'Sicherheitsgurte und Sitze',
            'Verbandkasten, Warndreieck, Warnleuchte', 'Feuerlöscher an Bord geprüft'],
        'Pumpenprüfung (DIN 14420)' => ['Sichtprüfung Pumpe und Armaturen', 'Trockensaugprüfung (Dichtheit)', 'Druckprüfung', 'Leistungsprüfung (Nennförderstrom)',
            'Entwässerung und Entlüftung', 'Tankfüllung und Tankanschluss'],
        'Monatliche Fahrzeugkontrolle' => ['Reifen und Luftdruck', 'Beleuchtung und Sondersignal', 'Motoröl und Kühlwasser', 'Kraftstoff mindestens ¾ voll',
            'Löschwassertank voll', 'Batterie / Ladeerhaltung angeschlossen', 'Funkgeräte und Handlampen geladen', 'Beladung vollständig'],
    ];

    protected $table = 'fv_deadlines';

    protected $fillable = ['vehicle_id', 'title', 'interval_months', 'due_on', 'lead_days', 'notes', 'due_mailed_at', 'overdue_mailed_at', 'is_active', 'steps', 'event_id'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'due_on' => 'date', 'interval_months' => 'integer', 'lead_days' => 'integer', 'is_active' => 'boolean',
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
