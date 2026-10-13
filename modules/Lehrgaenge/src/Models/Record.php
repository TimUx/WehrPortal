<?php

namespace Modules\Lehrgaenge\src\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Lehrgaenge\src\Services\RegistrationService;

/**
 * A Kamerad and one Lehrgang: Interesse gemeldet → bestätigt (the Ausbildungsleiter has entered the
 * days) → absolviert. Until then the Kamerad can cancel; the cancellation is kept with its reason.
 */
class Record extends Model
{
    public const STATUSES = [
        'requested' => 'Interesse gemeldet',
        'confirmed' => 'Bestätigt',
        'completed' => 'Absolviert',
        'cancelled' => 'Abgesagt',
        'rejected' => 'Abgelehnt',
    ];

    /** Still running: the Kamerad can cancel */
    public const OPEN = ['requested', 'confirmed'];

    protected $table = 'lq_records';

    protected $fillable = ['user_id', 'course_id', 'status', 'notes', 'starts_on', 'confirmed_at', 'start_reminded_at', 'completed_on', 'cancelled_at', 'cancel_reason', 'entered_manually', 'refresh_reminded_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'completed_on' => 'date', 'confirmed_at' => 'datetime', 'start_reminded_at' => 'datetime', 'cancelled_at' => 'datetime', 'entered_manually' => 'boolean', 'refresh_reminded_on' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    /** The confirmed days, in order */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'record_id')->orderBy('date')->orderBy('starts_at');
    }

    /** Until when an absolvierter Lehrgang with a refresher interval counts; null without one */
    public function validUntil(): ?Carbon
    {
        return $this->status === 'completed' && $this->completed_on && $this->course?->repeat_months
            ? $this->completed_on->copy()->addMonths($this->course->repeat_months) : null;
    }

    /** 'ok', 'soon' (within REFRESH_WARN_DAYS) or 'expired'; null without an interval */
    public function refreshState(): ?string
    {
        $until = $this->validUntil();
        $today = RegistrationService::today();

        return match (true) {
            $until === null => null,
            $until->lt($today) => 'expired',
            $until->lte($today->copy()->addDays(RegistrationService::REFRESH_WARN_DAYS)) => 'soon',
            default => 'ok',
        };
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
