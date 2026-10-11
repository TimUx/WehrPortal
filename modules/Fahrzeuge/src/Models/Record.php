<?php

namespace Modules\Fahrzeuge\src\Models;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A documented Prüfung, Wartung or Reparatur of a vehicle, with Kilometerstand, costs and Belege */
class Record extends Model
{
    public const KINDS = ['inspection' => 'Prüfung', 'service' => 'Wartung', 'repair' => 'Reparatur', 'other' => 'Sonstiges'];

    protected $table = 'fv_records';

    protected $fillable = ['vehicle_id', 'deadline_id', 'kind', 'title', 'done_on', 'mileage_km', 'cost_cents', 'provider', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['done_on' => 'date', 'mileage_km' => 'integer', 'cost_cents' => 'integer'];
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

    public function cost(): ?string
    {
        return $this->cost_cents !== null ? Money::format($this->cost_cents) : null;
    }
}
