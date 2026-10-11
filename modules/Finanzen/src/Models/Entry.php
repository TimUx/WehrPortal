<?php

namespace Modules\Finanzen\src\Models;

use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Buchung with Belegnummer: an Ausgabe (a Kamerad submits it, Kassenwart or Wehrführung approve
 * it, the Kassenwart pays it) or an Einnahme (booked by the Kassenwart).
 */
class Entry extends Model
{
    public const KINDS = ['expense' => 'Ausgabe', 'income' => 'Einnahme'];

    public const STATUSES = ['submitted' => 'Eingereicht', 'approved' => 'Genehmigt', 'rejected' => 'Abgelehnt', 'paid' => 'Bezahlt'];

    /** Count against the budget: approved ones are promised, paid ones spent */
    public const BOOKED = ['approved', 'paid'];

    protected $table = 'fin_entries';

    protected $fillable = ['year', 'number', 'kind', 'status', 'cost_center_id', 'title', 'description', 'vendor', 'amount_cents', 'booked_on',
        'submitted_by', 'decided_by', 'decided_at', 'decision_comment', 'paid_by', 'paid_at', 'payment_note'];

    protected function casts(): array
    {
        return ['year' => 'integer', 'number' => 'integer', 'amount_cents' => 'integer', 'booked_on' => 'date', 'decided_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class)->orderBy('id');
    }

    /** 2027-007 */
    public function code(): string
    {
        return $this->year.'-'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    public function isIncome(): bool
    {
        return $this->kind === 'income';
    }

    public function statusLabel(): string
    {
        return $this->isIncome() && $this->status === 'paid' ? 'Gebucht' : self::STATUSES[$this->status] ?? $this->status;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }
}
