<?php

namespace Modules\Expositionen\src\Models;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Einsatzbericht\src\Models\Report;

/** A Gefahrstoff-Exposition of one Einsatzkraft: proposed (draft), recorded, reported to the DGUV-ZED */
class Exposure extends Model
{
    use MassPrunable;

    /** GefStoffV § 14 (3): 40 years after the exposure */
    public const RETAIN_YEARS = 40;

    public const STATUSES = ['draft' => 'Vorschlag – bitte bestätigen', 'recorded' => 'Erfasst – noch nicht gemeldet', 'reported' => 'An die ZED gemeldet'];

    public const ACTIVITIES = [
        'innenangriff' => 'Brandbekämpfung im Innenangriff', 'aussenangriff' => 'Brandbekämpfung im Außenangriff',
        'nachloeschen' => 'Nachlösch- und Aufräumarbeiten', 'brandwache' => 'Brandwache', 'th' => 'Technische Hilfe',
        'abc' => 'Gefahrstoff-/ABC-Einsatz', 'dekon' => 'Dekontamination', 'hygiene' => 'Reinigung von Gerät, Fahrzeug, Kleidung',
        'uebung' => 'Übung (z. B. Brandübungsanlage)', 'sonstiges' => 'Sonstiges',
    ];

    /** Substances a Feuerwehr meets most; anything else is typed */
    public const SUBSTANCES = [
        'Brandrauch / Brandgase (PAK, Ruß)', 'Benzol', 'Asbest', 'Künstliche Mineralfasern (KMF)', 'Formaldehyd', 'Polychlorierte Biphenyle (PCB)',
        'Dioxine / Furane', 'Kraftstoffe (Benzin, Diesel)', 'Chlor', 'Ammoniak', 'Säuren / Laugen', 'Unbekannter Stoff',
    ];

    public const ROUTES = ['inhalativ' => 'eingeatmet', 'dermal' => 'über die Haut', 'oral' => 'verschluckt', 'unbekannt' => 'unbekannt'];

    public const PROTECTION = [
        'atemschutz' => 'Umluftunabhängiger Atemschutz', 'filter' => 'Filtergerät / FFP-Maske', 'csa' => 'Chemikalienschutzanzug',
        'schutzkleidung' => 'Schutzkleidung vollständig', 'handschuhe' => 'Schutzhandschuhe', 'keine' => 'Keine',
    ];

    protected $table = 'ex_exposures';

    protected $fillable = ['user_id', 'person_name', 'report_id', 'ext_location_id', 'occurred_on', 'occasion', 'activity', 'substances', 'duration_minutes', 'routes',
        'protection', 'notes', 'status', 'reported_on', 'zed_reference', 'recorded_by', 'retain_until'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date', 'reported_on' => 'date', 'retain_until' => 'date', 'duration_minutes' => 'integer',
            'activity' => 'encrypted:array', 'substances' => 'encrypted:array', 'notes' => 'encrypted', 'routes' => 'array', 'protection' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function organizationalLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'ext_location_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExposureEvent::class)->orderBy('created_at');
    }

    public function prunable(): Builder
    {
        return static::where('retain_until', '<', now()->toDateString());
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** "Brandbekämpfung im Innenangriff – Kellerbrand, Kunststoffe" */
    public function activityLabel(): string
    {
        $kind = self::ACTIVITIES[$this->activity['kind'] ?? ''] ?? 'Tätigkeit';

        return filled($this->activity['text'] ?? null) ? $kind.' – '.$this->activity['text'] : $kind;
    }

    /** "Brandrauch / Brandgase (PAK, Ruß), Asbest" */
    public function substanceList(): string
    {
        return implode(', ', $this->substances ?? []);
    }

    public function routeList(): string
    {
        return collect($this->routes ?? [])->map(fn ($r) => self::ROUTES[$r] ?? $r)->implode(', ');
    }

    public function protectionList(): string
    {
        return collect($this->protection ?? [])->map(fn ($p) => self::PROTECTION[$p] ?? $p)->implode(', ');
    }
}
