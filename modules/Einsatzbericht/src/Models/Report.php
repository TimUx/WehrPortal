<?php

namespace Modules\Einsatzbericht\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One Einsatz: Entwurf → abgeschlossen (by the Einsatzleiter) → freigegeben (by the Wehrführung) */
class Report extends Model
{
    public const STATUSES = ['draft' => 'Entwurf', 'active' => 'Aktuell', 'closed' => 'Abgeschlossen', 'released' => 'Freigegeben'];

    /** Still with the Einsatzleiter: a draft or a started (aktuell) Einsatz */
    public const OPEN = ['draft', 'active'];

    /** Counted in statistics and the Personalakte */
    public const DONE = ['closed', 'released'];

    public const CATEGORIES = [
        'brand_klein' => 'Brand – Kleinbrand',
        'brand_mittel' => 'Brand – Mittelbrand',
        'brand_gross' => 'Brand – Großbrand',
        'th' => 'Technische Hilfe',
        'first_responder' => 'First Responder',
        'fehlalarm' => 'Fehlalarm',
        'sonstiges' => 'Sonstiges',
    ];

    /** Fields the Wehrführung can make mandatory for closing a report */
    public const REQUIRABLE = [
        'keyword' => 'Einsatzstichwort',
        'dispatch_number' => 'Einsatznummer der Leitstelle',
        'alarmed_at' => 'Alarmierung',
        'arrived_at' => 'Eintreffen',
        'ended_at' => 'Einsatzende',
        'ready_at' => 'Wieder einsatzbereit',
        'address' => 'Adresse',
        'category' => 'Einsatzart',
        'details' => 'Angaben zur Einsatzart (geführte Abfrage)',
        'situation' => 'Lage beim Eintreffen',
        'measures' => 'Getroffene Maßnahmen',
        'commander_id' => 'Einsatzleiter',
        'crew' => 'Mindestens eine Einsatzkraft',
    ];

    public const DEFAULT_REQUIRED = ['keyword', 'alarmed_at', 'address', 'category', 'commander_id', 'crew'];

    public const TIMES = ['alarmed_at' => 'Alarmierung', 'departed_at' => 'Ausrücken', 'arrived_at' => 'Eintreffen', 'ended_at' => 'Einsatzende', 'ready_at' => 'Wieder einsatzbereit'];

    protected $table = 'eb_reports';

    protected $hidden = ['external_key', 'external_key_hash', 'closed_signature', 'released_signature'];

    protected $attributes = ['status' => 'draft', 'source' => 'manual', 'rescued' => 0, 'injured' => 0, 'dead' => 0, 'police' => false, 'ems' => false, 'fire_watch' => false];

    protected $fillable = [
        'number', 'dispatch_number', 'keyword', 'status',
        'alarmed_at', 'departed_at', 'arrived_at', 'ended_at', 'ready_at',
        'address', 'object', 'latitude', 'longitude', 'affected_parties', 'category',
        'situation', 'measures', 'equipment_used', 'rescued', 'injured', 'dead', 'property_damage', 'environmental_damage',
        'police', 'ems', 'neighbor_brigades', 'other_parties', 'handover', 'fire_watch', 'fire_watch_notes',
        'commander_id', 'created_by', 'closed_at', 'closed_by', 'released_at', 'released_by',
        'source', 'divera_id', 'alarm_message', 'external_key', 'external_key_hash',
        'details', 'closed_signature', 'released_signature', 'released_hash', 'parent_id', 'side_label', 'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'alarmed_at' => 'datetime', 'departed_at' => 'datetime', 'arrived_at' => 'datetime', 'ended_at' => 'datetime', 'ready_at' => 'datetime',
            'closed_at' => 'datetime', 'released_at' => 'datetime', 'reminded_at' => 'datetime', 'details' => 'array',
            'latitude' => 'float', 'longitude' => 'float',
            'rescued' => 'integer', 'injured' => 'integer', 'dead' => 'integer',
            'police' => 'boolean', 'ems' => 'boolean', 'fire_watch' => 'boolean',
        ];
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(ReportVehicle::class, 'report_id')->orderBy('is_standby')->orderBy('sort_order')->orderBy('id');
    }

    public function crew(): HasMany
    {
        return $this->hasMany(CrewMember::class, 'report_id');
    }

    /** The Einsatztagebuch: Lagemeldungen, important ones first, then newest first */
    public function messages(): HasMany
    {
        return $this->hasMany(SituationMessage::class, 'report_id')->orderByDesc('important')->orderByDesc('created_at')->orderByDesc('id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(ReportMaterial::class, 'report_id')->orderBy('category')->orderBy('name');
    }

    /** Still going: a draft without Einsatzende – Kameraden can send Lagemeldungen */
    public function isRunning(): bool
    {
        return $this->status === 'active' && $this->ended_at === null;
    }

    public function externalUnits(): HasMany
    {
        return $this->hasMany(ExternalUnit::class, 'report_id')->orderBy('created_at');
    }

    public function commander(): BelongsTo
    {
        return $this->belongsTo(User::class, 'commander_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** A Nebenbericht belongs to its Hauptbericht; only Hauptberichte count as Einsätze in the statistics */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function sides(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('number');
    }

    public function isSide(): bool
    {
        return $this->parent_id !== null;
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? '–';
    }

    /** From alarm to end (or ready), in minutes */
    public function durationMinutes(): ?int
    {
        $end = $this->ended_at ?? $this->ready_at;

        return $this->alarmed_at && $end && $end->gt($this->alarmed_at) ? (int) $this->alarmed_at->diffInMinutes($end) : null;
    }

    public static function formatMinutes(?int $minutes): string
    {
        return $minutes === null ? '–' : intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).' h';
    }

    /** "Navigation" in Google Maps: a plain link, no API key */
    public function navigationUrl(): ?string
    {
        if ($this->latitude && $this->longitude) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.$this->latitude.','.$this->longitude;
        }

        return filled($this->address) ? 'https://www.google.com/maps/dir/?api=1&destination='.rawurlencode($this->address) : null;
    }
}
