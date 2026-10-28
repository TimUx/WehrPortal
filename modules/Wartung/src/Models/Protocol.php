<?php

namespace Modules\Wartung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Ausruestung\src\Models\Equipment;

/**
 * A signed Prüfprotokoll: checklist results, findings, time and costs, who checked with which
 * Prüfer-ID or which Fachfirma. Never changed once signed (the database refuses it); a wrong one is
 * cancelled with a reason.
 */
class Protocol extends Model
{
    public const RESULTS = ['passed' => 'Bestanden', 'failed' => 'Nicht bestanden'];

    public const ITEM_RESULTS = ['ok' => 'in Ordnung', 'defect' => 'Mangel', 'na' => 'entfällt'];

    protected $table = 'wm_protocols';

    protected $fillable = ['year', 'number', 'template_id', 'equipment_id', 'equipment_code', 'equipment_name', 'title', 'performed_on', 'result',
        'checklist', 'findings', 'minutes', 'hourly_rate_cents', 'labor_cost_cents', 'material_cost_cents', 'external_cost_cents', 'performer',
        'inspector_user_id', 'inspector_name', 'inspector_number', 'qualification', 'provider_id', 'provider_name', 'signature', 'signed_at',
        'content_hash', 'recorded_by', 'voided_at', 'void_reason', 'voided_by'];

    protected $hidden = ['signature'];

    protected function casts(): array
    {
        return ['performed_on' => 'date', 'checklist' => 'array', 'signed_at' => 'datetime', 'voided_at' => 'datetime',
            'year' => 'integer', 'number' => 'integer', 'minutes' => 'integer', 'hourly_rate_cents' => 'integer',
            'labor_cost_cents' => 'integer', 'material_cost_cents' => 'integer', 'external_cost_cents' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class)->orderBy('id');
    }

    /** W-2027-004 */
    public function code(): string
    {
        return 'W-'.$this->year.'-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    public function passed(): bool
    {
        return $this->result === 'passed';
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function totalCents(): int
    {
        return $this->labor_cost_cents + $this->material_cost_cents + $this->external_cost_cents;
    }

    public function signatureDataUrl(): string
    {
        return 'data:image/png;base64,'.$this->signature;
    }

    public function performerLabel(): string
    {
        return $this->performer === 'external'
            ? ($this->provider_name ?? 'Fachfirma').' – '.$this->inspector_name
            : $this->inspector_name;
    }
}
