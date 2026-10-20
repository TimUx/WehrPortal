<?php

namespace Modules\Mitteilungen\src\Models;

use App\Models\Group;
use App\Models\MemberFunction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A notice on the start page; important ones are highlighted and cannot be dismissed. Meant for Abteilungen
 * and/or Funktionen (empty = everyone), with attachments and, on request, a Lesebestätigung.
 *
 * @property Carbon|null $visible_from
 * @property Carbon|null $visible_until
 */
class Announcement extends Model
{
    protected $fillable = ['title', 'body', 'important', 'needs_ack', 'visible_from', 'visible_until', 'created_by', 'ext_location_id'];

    protected function casts(): array
    {
        return ['important' => 'boolean', 'needs_ack' => 'boolean', 'visible_from' => 'datetime', 'visible_until' => 'datetime'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Audience; empty means everyone */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class);
    }

    /** Audience by Funktion (a Funktion includes its sub-functions) */
    public function functions(): BelongsToMany
    {
        return $this->belongsToMany(MemberFunction::class, 'announcement_function');
    }

    public function files(): HasMany
    {
        return $this->hasMany(AnnouncementFile::class)->select(['id', 'announcement_id', 'filename', 'mime', 'size']);
    }

    /** Who opened it, and who confirmed it (Lesebestätigung) */
    public function reads(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')->withPivot(['read_at', 'acknowledged_at']);
    }

    public function dismissedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_dismissals');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn ($q) => $q->whereNull('visible_until')->orWhere('visible_until', '>=', now()));
    }

    /** Meant for this Kamerad: no audience set, member of one of the Abteilungen, or holding one of the Funktionen */
    public function scopeFor(Builder $query, User $user): Builder
    {
        $groupIds = DB::table('group_user')->where('user_id', $user->id)->pluck('group_id')->all();
        $functions = DB::table('member_function_user')->where('user_id', $user->id)->pluck('member_function_id')->all();
        // A Funktion of the audience also covers its sub-functions, so look one level up
        $withParents = array_values(array_unique([
            ...array_map('intval', $functions),
            ...DB::table('member_functions')->whereIn('id', $functions)->whereNotNull('parent_id')->pluck('parent_id')->map(fn ($id) => (int) $id)->all(),
        ]));

        return $query->where(function (Builder $q) use ($user, $groupIds, $withParents) {
            $q->where(fn ($all) => $all
                ->whereNotExists(fn ($audience) => $audience->selectRaw('1')->from('announcement_group')
                    ->whereColumn('announcement_group.announcement_id', 'announcements.id'))
                ->whereNotExists(fn ($audience) => $audience->selectRaw('1')->from('announcement_function')
                    ->whereColumn('announcement_function.announcement_id', 'announcements.id')))
                ->orWhereExists(fn ($audience) => $audience->selectRaw('1')->from('announcement_group')
                    ->whereColumn('announcement_group.announcement_id', 'announcements.id')
                    ->whereIn('announcement_group.group_id', $groupIds))
                ->orWhereExists(fn ($audience) => $audience->selectRaw('1')->from('announcement_function')
                    ->whereColumn('announcement_function.announcement_id', 'announcements.id')
                    ->whereIn('announcement_function.member_function_id', $withParents));

            if (Schema::hasTable('x_location_user')) {
                $locationIds = DB::table('x_location_user')->where('user_id', $user->id)->pluck('location_id')
                    ->map(fn ($id) => (int) $id)->all();
                if ($user->getAttribute('ext_location_id')) {
                    $locationIds[] = (int) $user->getAttribute('ext_location_id');
                }
                $q->where(function (Builder $location) use ($locationIds) {
                    $location->whereNull('announcements.ext_location_id')
                        ->orWhereIn('announcements.ext_location_id', $locationIds);
                });
            } else {
                $q->whereNull('announcements.ext_location_id');
            }
        });
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->current()->for($user)
            ->where(fn ($q) => $q->where('important', true)
                ->orWhereDoesntHave('dismissedBy', fn ($d) => $d->where('users.id', $user->id)));
    }
}
