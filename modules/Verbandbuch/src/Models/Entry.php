<?php

namespace Modules\Verbandbuch\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An entry of the Verbandbuch (DGUV Information 204-021): who was hurt, when and where, what
 * happened, the injury, the first aid and who gave it, the witnesses. Signed when it is written
 * and never changed afterwards (the database refuses it); corrections are Nachträge.
 */
class Entry extends Model
{
    use MassPrunable;

    public const KINDS = ['injury' => 'Verletzung / Erste Hilfe', 'accident' => 'Unfall', 'incident' => 'Vorfall ohne Verletzung'];

    public const OCCASIONS = [
        'einsatz' => 'Einsatz', 'uebung' => 'Übungsdienst', 'dienst' => 'Sonstiger Dienst (z. B. Gerätepflege)',
        'jugend' => 'Jugendfeuerwehr', 'veranstaltung' => 'Veranstaltung', 'sonstiges' => 'Sonstiges',
    ];

    /** DGUV Vorschrift 1 § 24 (6): keep the documentation for five years */
    public const RETENTION_YEARS = 5;

    protected $table = 'vb_entries';

    protected $fillable = ['year', 'number', 'kind', 'occasion', 'occurred_at', 'location', 'injured_user_id', 'injured_name', 'description', 'injury',
        'witnesses', 'first_aid_at', 'first_aid_measures', 'first_aider_user_id', 'first_aider_name', 'doctor_visited', 'recorded_by', 'recorded_by_name',
        'content_hash', 'retain_until', 'hold', 'reported_at', 'report_note', 'reported_by', 'voided_at', 'void_reason', 'voided_by'];

    protected function casts(): array
    {
        return [
            'year' => 'integer', 'number' => 'integer', 'occurred_at' => 'datetime', 'first_aid_at' => 'datetime', 'retain_until' => 'date',
            'doctor_visited' => 'boolean', 'hold' => 'boolean', 'reported_at' => 'datetime', 'voided_at' => 'datetime',
            // Health data: encrypted with the APP_KEY, readable only through the app
            'description' => 'encrypted', 'injury' => 'encrypted', 'witnesses' => 'encrypted', 'first_aid_measures' => 'encrypted',
        ];
    }

    /** Deleted after the retention time, unless an entry is kept on purpose; the database's date, like its trigger */
    public function prunable(): Builder
    {
        return static::where('hold', false)->whereRaw('retain_until < CURRENT_DATE');
    }

    public function injuredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'injured_user_id');
    }

    public function firstAider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'first_aider_user_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(Signature::class)->orderBy('id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Amendment::class)->orderBy('id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class)->orderByDesc('id');
    }

    /** Entries this Kamerad is part of: hurt, first aider or recorder */
    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn ($q) => $q->where('injured_user_id', $user->id)->orWhere('first_aider_user_id', $user->id)->orWhere('recorded_by', $user->id));
    }

    /** Accidents with a doctor's visit still without the note that the Unfallanzeige was sent */
    public function scopeAwaitingReport(Builder $query): Builder
    {
        return $query->where('kind', 'accident')->where('doctor_visited', true)->whereNull('reported_at')->whereNull('voided_at');
    }

    /** 2027-003 */
    public function code(): string
    {
        return $this->year.'-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function occasionLabel(): string
    {
        return self::OCCASIONS[$this->occasion] ?? $this->occasion;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function needsReport(): bool
    {
        return $this->kind === 'accident' && $this->doctor_visited && $this->reported_at === null && ! $this->isVoided();
    }
}
