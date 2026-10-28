<?php

namespace Modules\Kalender\src\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Services\LocationContext;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CalendarLocationAccess
{
    public function enabled(): bool
    {
        return (bool) config('locations.enabled') && Schema::hasTable('x_locations')
            && Schema::hasTable('kal_event_location') && Schema::hasTable('kal_poll_location');
    }

    /** @return Collection<int, string> */
    public function options(User $user): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        $ids = $user->canManageKalender()
            ? Location::query()->active()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $user->standardRoleLocationIds('kalender', ['terminverwalter']);

        return Location::query()->active()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('name')
            ->pluck('name', 'id');
    }

    /** @return list<int> */
    public function defaultIds(User $user): array
    {
        $options = $this->options($user);
        if ($options->isEmpty()) {
            return [];
        }

        $current = app(LocationContext::class)->forUser($user)['current'];

        return [$current !== null && $options->has($current) ? $current : (int) $options->keys()->first()];
    }

    /** @param list<int|string> $locationIds
     * @param  list<int|string>  $groupIds
     * @param  list<int|string>  $functionIds
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    public function resolveTargets(?User $user, array $locationIds, array $groupIds, array $functionIds, array $userIds, array $data, bool $requireManager): array
    {
        if (! $this->enabled()) {
            return [];
        }

        if ($locationIds === []) {
            if ($user && $requireManager) {
                $locationIds = $this->defaultIds($user);
            } else {
                $locationIds = DB::table('groups')->whereIn('id', array_map('intval', $groupIds))
                    ->whereNotNull('ext_location_id')->distinct()->pluck('ext_location_id')->map(fn ($id) => (int) $id)->all();
                $locationIds = [...$locationIds, ...DB::table('member_functions')->whereIn('id', array_map('intval', $functionIds))
                    ->whereNotNull('ext_location_id')->distinct()->pluck('ext_location_id')->map(fn ($id) => (int) $id)->all()];
                $locationIds = array_values(array_unique($locationIds));
                if ($locationIds === [] && ! empty($data['ext_location_id'])) {
                    $locationIds = [(int) $data['ext_location_id']];
                }
                if ($locationIds === [] && $userIds !== []) {
                    $ids = array_map('intval', $userIds);
                    $locationIds = DB::table('users')->whereIn('id', $ids)->whereNotNull('ext_location_id')
                        ->pluck('ext_location_id')->map(fn ($id) => (int) $id)->all();
                    $locationIds = [...$locationIds, ...DB::table('x_location_user')->whereIn('user_id', $ids)
                        ->pluck('location_id')->map(fn ($id) => (int) $id)->all()];
                }
                if ($locationIds === []) {
                    $locationIds = [(int) Location::default()->getKey()];
                }
            }
        }

        return $this->assertTargets($user, $locationIds, $groupIds, $functionIds, $userIds, $requireManager);
    }

    /** @param  list<int|string>  $locationIds
     * @param  list<int|string>  $groupIds
     * @param  list<int|string>  $functionIds
     * @param  list<int|string>  $userIds
     * @return list<int>
     */
    public function assertTargets(?User $user, array $locationIds, array $groupIds = [], array $functionIds = [], array $userIds = [], bool $requireManager = true): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $locationIds = array_values(array_unique(array_map('intval', $locationIds)));
        if ($locationIds === [] || in_array(0, $locationIds, true)) {
            throw ValidationException::withMessages(['locationIds' => 'Bitte mindestens einen aktiven Standort auswählen.']);
        }

        $activeIds = Location::query()->active()->whereIn('id', $locationIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($activeIds) !== count($locationIds)
            || ($requireManager && (! $user || ! $user->canManageKalenderLocations($locationIds)))) {
            throw ValidationException::withMessages(['locationIds' => 'Mindestens ein ausgewählter Standort darf nicht verwaltet werden.']);
        }

        $this->assertTargetsAtLocations('groups', $groupIds, $locationIds);
        $this->assertTargetsAtLocations('member_functions', $functionIds, $locationIds, allowShared: true);

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds !== []) {
            $matching = DB::table('users')->whereIn('id', $userIds)->where('is_active', true)
                ->where(function ($query) use ($locationIds) {
                    $query->whereIn('ext_location_id', $locationIds)->orWhereExists(fn ($membership) => $membership
                        ->selectRaw('1')->from('x_location_user')
                        ->whereColumn('x_location_user.user_id', 'users.id')
                        ->whereIn('x_location_user.location_id', $locationIds));
                })->count();
            if ($matching !== count($userIds)) {
                throw ValidationException::withMessages(['inviteeIds' => 'Alle eingeladenen Kameraden müssen einem ausgewählten Standort angehören.']);
            }
        }

        return $locationIds;
    }

    /** @param  list<int|string>  $targetIds
     * @param  list<int>  $locationIds
     */
    private function assertTargetsAtLocations(string $table, array $targetIds, array $locationIds, bool $allowShared = false): void
    {
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));
        if ($targetIds === []) {
            return;
        }

        $query = DB::table($table)->whereIn('id', $targetIds)->where(function ($query) use ($locationIds, $allowShared) {
            $query->whereIn('ext_location_id', $locationIds);
            if ($allowShared) {
                $query->orWhereNull('ext_location_id');
            }
        });
        if ($query->count() !== count($targetIds)) {
            throw ValidationException::withMessages(['groupIds' => 'Abteilungen und Funktionen müssen zu einem ausgewählten Standort gehören.']);
        }
    }

    /**
     * @param  EloquentCollection<int, User>  $audience
     * @param  list<int>  $locationIds
     * @return EloquentCollection<int, User>
     */
    public function restrictAudience(EloquentCollection $audience, array $locationIds): EloquentCollection
    {
        if (! $this->enabled() || $locationIds === []) {
            return $audience;
        }

        $memberIds = DB::table('users')->where('is_active', true)
            ->where(function ($query) use ($locationIds) {
                $query->whereIn('ext_location_id', $locationIds)->orWhereExists(fn ($membership) => $membership
                    ->selectRaw('1')->from('x_location_user')
                    ->whereColumn('x_location_user.user_id', 'users.id')
                    ->whereIn('x_location_user.location_id', $locationIds));
            })->pluck('id')->flip();

        return new EloquentCollection($audience->filter(fn (User $user) => $memberIds->has($user->id))->all());
    }
}
