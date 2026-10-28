<?php

namespace Modules\Kalender\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\Place;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Support\LocationAssignable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Modules\Kalender\src\Support\Audience;

/**
 * @property int|null $ext_location_id
 * @property string|null $managed_by
 */
class Event extends Model implements LocationAssignable
{
    public const TYPES = [
        'uebung' => 'Übung',
        'dienst' => 'Dienst',
        'veranstaltung' => 'Veranstaltung',
        'wartung' => 'Wartung',
        'arbeitsdienst' => 'Arbeitsdienst',
        'sonstiges' => 'Sonstiges',
    ];

    /** Tailwind classes per type for chips and badges */
    public const COLORS = [
        'uebung' => 'bg-red-100 text-red-800 border-red-200',
        'dienst' => 'bg-blue-100 text-blue-800 border-blue-200',
        'veranstaltung' => 'bg-green-100 text-green-800 border-green-200',
        'wartung' => 'bg-amber-100 text-amber-800 border-amber-200',
        'arbeitsdienst' => 'bg-orange-100 text-orange-800 border-orange-200',
        'sonstiges' => 'bg-gray-100 text-gray-700 border-gray-200',
    ];

    protected $table = 'kal_events';

    protected $attributes = ['type' => 'uebung', 'all_day' => false, 'rsvp' => false, 'is_public' => false];

    protected $fillable = [
        'title', 'type', 'starts_at', 'ends_at', 'all_day', 'location', 'partners', 'description', 'rsvp', 'rsvp_deadline',
        'min_participants', 'is_public', 'reminder_sent_at', 'series_id', 'source_id', 'external_uid', 'cancelled_at', 'created_by',
        'place_id', 'dress_code_ids', 'managed_by', 'ext_location_id',
    ];

    /** Modules that keep events in sync, and where to change them */
    public const MANAGED_BY = [
        'uebungsdienste' => 'Übungsdienstplaner',
        'schichtplan' => 'Schichtplan',
        'fuehrerscheine' => 'Führerscheine',
        'fahrzeuge' => 'Fahrzeugverwaltung',
    ];

    protected function casts(): array
    {
        return [
            'dress_code_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'rsvp_deadline' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'all_day' => 'boolean',
            'rsvp' => 'boolean',
            'is_public' => 'boolean',
            'min_participants' => 'integer',
        ];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'kal_event_group', 'event_id', 'group_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'kal_event_location', 'event_id', 'location_id');
    }

    public function canAssignLocation(User $user, int $locationId): bool
    {
        return $user->canManageKalenderLocations([$locationId]) || isset(self::MANAGED_BY[$this->managed_by]);
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        if (config('locations.enabled') && Schema::hasTable('kal_event_location')) {
            $ids = $this->locations()->pluck('x_locations.id')->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                return $ids;
            }
        }

        return $this->ext_location_id === null ? [] : [(int) $this->ext_location_id];
    }

    /** Funktionen of the target group (each includes its sub-functions) */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'kal_event_function', 'event_id', 'function_id');
    }

    /** Kameraden invited individually */
    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kal_event_user', 'event_id', 'user_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class, 'event_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'source_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Terminverwalter see everything; everyone else the events for all (no target group set), for
     * their Abteilungen or Funktionen, and those they are invited to personally.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canManageKalender()) {
            return $query;
        }

        $locationIds = $user->standardRoleLocationIds('kalender', ['terminverwalter']);
        if ($locationIds !== []) {
            return $query->where(function (Builder $query) use ($locationIds, $user) {
                $query->where(function (Builder $query) use ($locationIds) {
                    $query->whereIn('kal_events.ext_location_id', $locationIds);
                    if (config('locations.enabled') && Schema::hasTable('kal_event_location')) {
                        $query->orWhereExists(fn ($locations) => $locations->selectRaw('1')->from('kal_event_location')
                            ->whereColumn('kal_event_location.event_id', 'kal_events.id')
                            ->whereIn('kal_event_location.location_id', $locationIds));
                    }
                })->orWhere(fn (Builder $query) => Audience::scope($query, $user));
            });
        }

        return Audience::scope($query, $user);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->whereNull('cancelled_at');
    }

    /** Overlaps [from, until) */
    public function scopeBetween(Builder $query, $from, $until): Builder
    {
        return $query->where('starts_at', '<', $until)->where('ends_at', '>', $from);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at')->where('ends_at', '>', now())->orderBy('starts_at');
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function colorClasses(): string
    {
        return self::COLORS[$this->type] ?? self::COLORS['sonstiges'];
    }

    public function isExternal(): bool
    {
        return $this->source_id !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** Rückmeldung still possible */
    public function acceptsResponses(): bool
    {
        return $this->rsvp && ! $this->isCancelled() && ! $this->ends_at->isPast()
            && ($this->rsvp_deadline === null || $this->rsvp_deadline->isFuture());
    }

    /** "Di, 14.06.2027 · 19:00–21:30 Uhr" or "Sa, 12.06.2027 (ganztägig)" in Berlin time */
    public function timeLabel(): string
    {
        $days = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
        $start = $this->starts_at->copy()->setTimezone('Europe/Berlin');
        $end = $this->ends_at->copy()->setTimezone('Europe/Berlin');

        if ($this->all_day) {
            $last = $end->copy()->subSecond();

            return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').($last->isSameDay($start) ? '' : ' – '.$days[$last->dayOfWeek].', '.$last->format('d.m.Y')).' (ganztägig)';
        }

        return $days[$start->dayOfWeek].', '.$start->format('d.m.Y').' · '.$start->format('H:i').'–'
            .($end->isSameDay($start) ? $end->format('H:i') : $days[$end->dayOfWeek].', '.$end->format('d.m.Y H:i')).' Uhr';
    }
}
