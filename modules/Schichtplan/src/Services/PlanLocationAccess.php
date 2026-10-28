<?php

namespace Modules\Schichtplan\src\Services;

use App\Models\Group;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PlanLocationAccess
{
    public function enabled(): bool
    {
        return config('locations.enabled')
            && Schema::hasTable('sp_plans')
            && Schema::hasColumn('sp_plans', 'ext_location_id');
    }

    public function canCreate(User $user): bool
    {
        return $this->enabled()
            ? $user->canManageSchichtplan() || $this->managedLocationIds($user) !== []
            : $user->canManageSchichtplan();
    }

    public function canManage(User $user, ?int $locationId): bool
    {
        return $this->enabled()
            ? $user->canManageSchichtplan() || $user->canManageSchichtplanAt($locationId)
            : $user->canManageSchichtplan();
    }

    /** @return list<int> */
    public function managedLocationIds(User $user): array
    {
        if (! $this->enabled()) {
            return [];
        }

        if ($user->canManageSchichtplan()) {
            return Location::query()->active()->orderBy('sort_order')->orderBy('name')->pluck('id')
                ->map(fn ($id) => (int) $id)->all();
        }

        return $user->standardRoleLocationIds('schichtplan', ['planer']);
    }

    /** @return Collection<int, string> */
    public function locationOptions(User $user): Collection
    {
        if (! $this->enabled()) {
            return collect();
        }

        $ids = $this->managedLocationIds($user);

        return Location::query()->active()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
    }

    public function defaultLocationId(User $user): ?int
    {
        $ids = $this->managedLocationIds($user);
        $home = (int) $user->ext_location_id;

        if (in_array($home, $ids, true)) {
            return $home;
        }

        return $ids[0] ?? null;
    }

    /** @return Collection<int, string> */
    public function groupOptions(?int $locationId): Collection
    {
        if (! $this->enabled()) {
            return collect(Group::options());
        }
        if ($locationId === null) {
            return collect();
        }

        return Group::query()->withoutGlobalScopes()->where('ext_location_id', $locationId)
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id');
    }

    public function isMemberAt(User $user, ?int $locationId): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $locationId !== null
            && DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()
            && DB::table('users')->where('id', $user->id)->where('is_active', true)
                ->where(fn ($query) => $query->where('ext_location_id', $locationId)
                    ->orWhereExists(fn ($membership) => $membership->selectRaw('1')->from('x_location_user')
                        ->whereColumn('x_location_user.user_id', 'users.id')->where('location_id', $locationId)))
                ->exists();
    }

    public function assertCanAssignLocation(User $user, ?int $locationId, ?int $previousLocationId = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($locationId === null || ! $this->canManage($user, $locationId)
            || ($previousLocationId !== null && ! $this->canManage($user, $previousLocationId))) {
            throw ValidationException::withMessages([
                'locationId' => 'Du darfst Schichtpläne nur an einem Standort verwalten, für den du als Planer eingetragen bist.',
            ]);
        }
    }

    /**
     * The selected department, audience departments, participants and station leads must all belong
     * to the plan's one Standort.
     *
     * @param  list<int>  $groupIds
     * @param  list<int>  $userIds
     */
    public function assertPlanContentsAt(?int $locationId, ?int $groupId, array $groupIds = [], array $userIds = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        if ($locationId === null || ! DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['locationId' => 'Bitte wähle einen aktiven Standort.']);
        }

        $groupIds = array_values(array_unique(array_filter([$groupId, ...$groupIds])));
        if ($groupIds !== [] && DB::table('groups')->whereIn('id', $groupIds)->where('ext_location_id', $locationId)->count() !== count($groupIds)) {
            throw ValidationException::withMessages(['groupId' => 'Alle Abteilungen müssen zum ausgewählten Standort gehören.']);
        }

        foreach (array_unique($userIds) as $userId) {
            $user = User::query()->withoutGlobalScopes()->find($userId);
            if (! $user || ! $this->isMemberAt($user, $locationId)) {
                throw ValidationException::withMessages(['locationId' => 'Teilnehmende und Stationsverantwortliche müssen dem ausgewählten Standort angehören.']);
            }
        }
    }
}
