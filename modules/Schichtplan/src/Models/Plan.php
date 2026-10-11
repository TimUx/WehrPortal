<?php

namespace Modules\Schichtplan\src\Models;

use App\Models\Group;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    protected $table = 'sp_plans';

    protected $attributes = ['status' => self::DRAFT, 'shift_minutes' => 60, 'max_consecutive' => 2];

    protected $fillable = [
        'title', 'location', 'starts_at', 'ends_at', 'shift_minutes', 'max_consecutive',
        'group_id', 'status', 'published_at', 'mailed_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'published_at' => 'datetime',
            'mailed_at' => 'datetime',
            'shift_minutes' => 'integer',
            'max_consecutive' => 'integer',
        ];
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class, 'plan_id')->orderBy('sort_order')->orderBy('id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(Slot::class, 'plan_id')->orderBy('starts_at');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class, 'plan_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'sp_participants', 'plan_id', 'user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class, 'plan_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Everyone the plan concerns: participants and Stationsverantwortliche */
    public function concerns(User $user): bool
    {
        return $this->participants()->where('user_id', $user->id)->exists()
            || $this->stations()->where('lead_user_id', $user->id)->exists();
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /** "Sa, 13.06.2027 · 14:00–22:00 Uhr" in Berlin time */
    public function timeLabel(): string
    {
        $start = $this->starts_at->copy()->setTimezone('Europe/Berlin');
        $end = $this->ends_at->copy()->setTimezone('Europe/Berlin');
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];

        return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').' · '.$start->format('H:i').'–'.$end->format('H:i').' Uhr'
            .($end->isSameDay($start) ? '' : ' (bis '.$end->format('d.m.').')');
    }
}
