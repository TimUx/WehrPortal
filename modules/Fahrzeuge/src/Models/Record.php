<?php

namespace Modules\Fahrzeuge\src\Models;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/** A documented Prüfung, Wartung or Reparatur of a vehicle, with Kilometerstand, costs and Belege */
class Record extends Model
{
    public const KINDS = ['inspection' => 'Prüfung', 'service' => 'Wartung', 'repair' => 'Reparatur', 'other' => 'Sonstiges'];

    /** Result of a Prüfung and the Einsatzbereitschaft it sets */
    public const RESULTS = ['passed' => 'Bestanden', 'limited' => 'Mit Mängeln – bedingt einsatzbereit', 'failed' => 'Nicht bestanden – nicht einsatzbereit'];

    public const STEP_STATES = ['ok' => 'in Ordnung', 'defect' => 'Mangel', 'na' => 'entfällt'];

    protected $table = 'fv_records';

    protected $fillable = ['vehicle_id', 'deadline_id', 'kind', 'title', 'done_on', 'mileage_km', 'cost_cents', 'provider', 'notes', 'created_by',
        'invoice_number', 'result', 'checklist', 'signature', 'signer_name', 'signed_at', 'finance_entry_id'];

    protected $hidden = ['signature'];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'signed_at' => 'datetime', 'done_on' => 'date', 'mileage_km' => 'integer', 'cost_cents' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function deadline(): BelongsTo
    {
        return $this->belongsTo(Deadline::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function resultLabel(): ?string
    {
        return $this->result ? self::RESULTS[$this->result] ?? $this->result : null;
    }

    /** @return list<array{step: string, state: string, note: ?string}> */
    public function defects(): array
    {
        return array_values(array_filter($this->checklist ?? [], fn ($s) => ($s['state'] ?? '') === 'defect'));
    }

    public function cost(): ?string
    {
        return $this->cost_cents !== null ? Money::format($this->cost_cents) : null;
    }
}
