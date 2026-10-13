<?php

namespace Modules\Wasserkarte\src\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Löschwasserquelle with position, status and performance data */
class Source extends Model
{
    public const KINDS = [
        'unterflur' => 'Unterflurhydrant', 'ueberflur' => 'Überflurhydrant', 'teich' => 'Löschwasserteich', 'brunnen' => 'Löschwasserbrunnen',
        'behaelter' => 'Löschwasserbehälter / Zisterne', 'gewaesser' => 'Offenes Gewässer / Saugstelle',
    ];

    public const STATUSES = ['available' => 'Verfügbar', 'defective' => 'Defekt', 'locked' => 'Gesperrt'];

    /** Map colours: green, red, grey */
    public const COLORS = ['available' => '#16a34a', 'defective' => '#dc2626', 'locked' => '#6b7280'];

    protected $table = 'wk_sources';

    protected $fillable = ['number', 'osm_id', 'kind', 'latitude', 'longitude', 'location', 'status', 'status_note', 'flow_lpm', 'pressure_bar', 'capacity_m3',
        'pipe_dn', 'suction_height_m', 'operator', 'inspection_interval_months', 'last_inspected_on', 'next_inspection_on', 'notes', 'is_active', 'due_mailed_at'];

    protected $attributes = ['status' => 'available'];

    protected function casts(): array
    {
        return ['osm_id' => 'integer', 'latitude' => 'float', 'longitude' => 'float', 'flow_lpm' => 'integer', 'pressure_bar' => 'float', 'capacity_m3' => 'integer',
            'pipe_dn' => 'integer', 'suction_height_m' => 'float', 'inspection_interval_months' => 'integer', 'last_inspected_on' => 'date', 'next_inspection_on' => 'date',
            'is_active' => 'boolean', 'due_mailed_at' => 'datetime'];
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class)->latest('inspected_on')->latest('id');
    }

    public function defects(): HasMany
    {
        return $this->hasMany(Defect::class)->latest('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(StatusEvent::class)->latest('created_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function label(): string
    {
        return trim(($this->number ? $this->number.' · ' : '').$this->kindLabel());
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function isHydrant(): bool
    {
        return in_array($this->kind, ['unterflur', 'ueberflur'], true);
    }

    public function inspectionOverdue(): bool
    {
        return $this->next_inspection_on !== null && $this->next_inspection_on->lt(now('Europe/Berlin')->startOfDay());
    }

    /** Prüfung overdue or due within the next 30 days: an amber ring on the map */
    public function inspectionDue(): bool
    {
        return $this->next_inspection_on !== null && $this->next_inspection_on->lte(now('Europe/Berlin')->startOfDay()->addDays(30));
    }

    /** The code on a QR label at the source, resolved by /i/{code} */
    public function scanCode(): string
    {
        return 'WK-'.$this->id;
    }

    /** "1.600 l/min · 4,5 bar · DN 100" or the capacity */
    public function performance(): string
    {
        return collect([
            $this->flow_lpm ? number_format($this->flow_lpm, 0, ',', '.').' l/min' : null,
            $this->pressure_bar ? str_replace('.', ',', (string) $this->pressure_bar).' bar' : null,
            $this->pipe_dn ? 'DN '.$this->pipe_dn : null,
            $this->capacity_m3 ? number_format($this->capacity_m3, 0, ',', '.').' m³' : null,
            $this->suction_height_m ? 'Saughöhe '.str_replace('.', ',', (string) $this->suction_height_m).' m' : null,
        ])->filter()->implode(' · ');
    }

    public function navigationUrl(): string
    {
        return 'https://www.google.com/maps/dir/?api=1&destination='.$this->latitude.','.$this->longitude;
    }
}
