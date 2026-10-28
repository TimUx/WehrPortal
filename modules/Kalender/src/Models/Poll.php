<?php

namespace Modules\Kalender\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
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
 * A Terminabfrage: date proposals the target group answers with yes/maybe/no; the Terminverwalter
 * fixes one, which becomes an event of the Kalender.
 *
 * @property int|null $ext_location_id
 */
class Poll extends Model implements LocationAssignable
{
    protected $table = 'kal_polls';

    protected $attributes = ['type' => 'veranstaltung'];

    protected $fillable = ['title', 'type', 'location', 'description', 'deadline', 'closed_at', 'event_id', 'reminder_sent_at', 'created_by', 'ext_location_id'];

    protected function casts(): array
    {
        return ['deadline' => 'datetime', 'closed_at' => 'datetime', 'reminder_sent_at' => 'datetime'];
    }

    public function options(): HasMany
    {
        return $this->hasMany(PollOption::class, 'poll_id')->orderBy('starts_at');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'kal_poll_group', 'poll_id', 'group_id');
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'kal_poll_location', 'poll_id', 'location_id');
    }

    public function canAssignLocation(User $user, int $locationId): bool
    {
        return $user->canManageKalenderLocations([$locationId]);
    }

    /** @return list<int> */
    public function locationIds(): array
    {
        if (config('locations.enabled') && Schema::hasTable('kal_poll_location')) {
            $ids = $this->locations()->pluck('x_locations.id')->map(fn ($id) => (int) $id)->all();
            if ($ids !== []) {
                return $ids;
            }
        }

        return $this->ext_location_id === null ? [] : [(int) $this->ext_location_id];
    }

    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'kal_poll_function', 'poll_id', 'function_id');
    }

    public function invitees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'kal_poll_user', 'poll_id', 'user_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->canManageKalender()) {
            return $query;
        }

        $locationIds = $user->standardRoleLocationIds('kalender', ['terminverwalter']);
        if ($locationIds !== []) {
            return $query->where(function (Builder $query) use ($locationIds, $user) {
                $query->where(function (Builder $query) use ($locationIds) {
                    $query->whereIn('kal_polls.ext_location_id', $locationIds);
                    if (config('locations.enabled') && Schema::hasTable('kal_poll_location')) {
                        $query->orWhereExists(fn ($locations) => $locations->selectRaw('1')->from('kal_poll_location')
                            ->whereColumn('kal_poll_location.poll_id', 'kal_polls.id')
                            ->whereIn('kal_poll_location.location_id', $locationIds));
                    }
                })->orWhere(fn (Builder $query) => Audience::scope($query, $user));
            });
        }

        return Audience::scope($query, $user);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && ($this->deadline === null || $this->deadline->isFuture());
    }

    public function isDecided(): bool
    {
        return $this->event_id !== null;
    }

    public function typeLabel(): string
    {
        return Event::TYPES[$this->type] ?? $this->type;
    }
}
