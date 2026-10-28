<?php

namespace Modules\Ausruestung\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ausruestung\src\Enums\LoanStatus;

class Loan extends Model
{
    protected $table = 'ar_loans';

    protected $fillable = [
        'equipment_id', 'user_id', 'status', 'starts_at', 'due_at', 'handed_out_at', 'returned_at',
        'overdue_reminded_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => LoanStatus::class,
            'starts_at' => 'datetime',
            'due_at' => 'datetime',
            'handed_out_at' => 'datetime',
            'returned_at' => 'datetime',
            'overdue_reminded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [LoanStatus::Reserved->value, LoanStatus::Active->value]);
    }

    /** Overlaps [from, until) – the same half-open rule as vehicle bookings */
    public function scopeOverlapping(Builder $query, $from, $until): Builder
    {
        return $query->where('starts_at', '<', $until)->where('due_at', '>', $from);
    }

    public function isOverdue(): bool
    {
        return $this->status === LoanStatus::Active && $this->due_at->isPast();
    }
}
