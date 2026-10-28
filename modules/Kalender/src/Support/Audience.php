<?php

namespace Modules\Kalender\src\Support;

use App\Models\MemberFunction;
use App\Models\User;
use Extensions\Locations\Scopes\LocationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Who an event or a Terminabfrage is for: members of its Abteilungen, Kameraden with one of its
 * Funktionen (a Funktion includes its sub-functions) and the ones invited by name; everyone with
 * access to the Kalender when none is set. Models need the relations groups, functions, invitees.
 */
class Audience
{
    /** The Kameraden with access to the Kalender */
    public static function users(Collection $groupIds, Collection $functionIds, Collection $userIds): EloquentCollection
    {
        $functions = config('locations.enabled')
            ? MemberFunction::withoutGlobalScope(LocationScope::class)->whereIn('id', $functionIds)->orWhereIn('parent_id', $functionIds)->pluck('id')
            : MemberFunction::idsWithChildren($functionIds->all());
        $everyone = $groupIds->isEmpty() && $functionIds->isEmpty() && $userIds->isEmpty();

        return User::active()->when(config('locations.enabled'), fn ($query) => $query->withoutGlobalScope(LocationScope::class))->orderBy('name')
            ->when(! $everyone, fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('groups', fn ($g) => $g->when(config('locations.enabled'), fn ($g) => $g->withoutGlobalScope(LocationScope::class))->whereIn('groups.id', $groupIds))
                ->orWhereHas('functions', fn ($f) => $f->when(config('locations.enabled'), fn ($f) => $f->withoutGlobalScope(LocationScope::class))->whereIn('member_functions.id', $functions))
                ->orWhereIn('id', $userIds)))
            ->get()
            ->filter(fn (User $u) => $u->canUseModule('kalender'))
            ->values();
    }

    /** Restricts a query of events or polls to those meant for the Kamerad */
    public static function scope(Builder $query, User $user): Builder
    {
        $functions = $user->functions()->pluck('member_functions.id');
        // A Funktion of the target group also covers its sub-functions, so look one level up
        $withParents = $functions->merge(MemberFunction::whereIn('id', $functions)->whereNotNull('parent_id')->pluck('parent_id'))->unique();

        return $query->where(fn ($q) => $q->where(fn ($all) => $all->doesntHave('groups')->doesntHave('functions')->doesntHave('invitees'))
            ->orWhereHas('groups', fn ($g) => $g->whereIn('groups.id', $user->groups()->pluck('groups.id')))
            ->orWhereHas('functions', fn ($f) => $f->whereIn('member_functions.id', $withParents))
            ->orWhereHas('invitees', fn ($i) => $i->where('users.id', $user->id)));
    }
}
