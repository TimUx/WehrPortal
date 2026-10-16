<?php

namespace Modules\Fahrzeugbuchung\src\Models;

use App\Models\Group;
use App\Models\User;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    protected static function newFactory(): Factory
    {
        return BookingFactory::new();
    }

    public const STATUS_CONFIRMED = 'confirmed';

    /** Overlaps a confirmed booking and waits for a decider */
    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'group_id',
        'starts_at',
        'ends_at',
        'purpose',
        'destination',
        'notes',
        'status',
        'cancelled_at',
        'end_reminder_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'end_reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A new end time gets a new reminder
        static::updating(function (Booking $booking) {
            if ($booking->isDirty('ends_at')) {
                $booking->end_reminder_sent_at = null;
            }
        });
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** The decision this booking requested (only for conflict requests) */
    public function decision(): HasOne
    {
        return $this->hasOne(BookingDecision::class);
    }

    /** Messages between the members about this booking, oldest first */
    public function comments(): HasMany
    {
        return $this->hasMany(BookingComment::class)->orderBy('created_at')->orderBy('id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Confirmed, started and not over yet */
    public function isRunning(): bool
    {
        return ! $this->isCancelled()
            && ! $this->isPending()
            && ! $this->isRejected()
            && ! $this->starts_at->isFuture()
            && $this->ends_at->isFuture();
    }

    /** Confirmed and not cancelled – these block the vehicle */
    public function scopeActive($query)
    {
        return $query->whereNull('cancelled_at')->where('status', self::STATUS_CONFIRMED);
    }

    /** Shown in the calendar: confirmed bookings plus open conflict requests */
    public function scopeVisible($query)
    {
        return $query->whereNull('cancelled_at')
            ->whereIn('status', [self::STATUS_CONFIRMED, self::STATUS_PENDING]);
    }

    public function scopeUpcoming($query)
    {
        return $query->active()->where('starts_at', '>=', now());
    }

    public function scopePast($query)
    {
        return $query->active()->where('ends_at', '<', now());
    }
}
