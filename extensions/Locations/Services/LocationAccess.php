<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Who may see and manage which Standort. A global admin is a User with is_admin and sees everything;
 * a Standort-Admin is a user with role "admin" in x_location_user and manages only that Standort.
 * Queries use the query builder, not User models, so the LocationScope can call this without recursion.
 */
class LocationAccess
{
    /** @var array<int, array<int, string>> user id => [location id => role] */
    private array $memo = [];

    public function isGlobalAdmin(User $user): bool
    {
        return (bool) $user->is_admin;
    }

    /** @return array<int, string> location id => role (member|admin); the primary Standort counts as member */
    public function roles(User $user): array
    {
        return $this->memo[$user->getKey()] ??= $this->load($user);
    }

    /** @return list<int> Standorte a user belongs to (all of them for a global admin) */
    public function visibleLocationIds(User $user): array
    {
        if ($this->isGlobalAdmin($user) || app(StandardRoleAssignments::class)->hasGlobalProfile($user, 'gemeindebrandinspektor')) {
            return Location::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return array_keys($this->roles($user));
    }

    /** @return list<int> Standorte the user administers */
    public function adminLocationIds(User $user): array
    {
        if ($this->isGlobalAdmin($user)) {
            return $this->visibleLocationIds($user);
        }

        return array_keys(array_filter($this->roles($user), fn ($role) => $role === 'admin'));
    }

    public function isLocationAdmin(User $user): bool
    {
        return $user->is_active && ! $this->isGlobalAdmin($user) && $this->adminLocationIds($user) !== [];
    }

    public function canManage(User $user, Location $location): bool
    {
        return $user->is_active && ($this->isGlobalAdmin($user) || in_array((int) $location->getKey(), $this->adminLocationIds($user), true));
    }

    public function canSee(User $user, int $locationId): bool
    {
        return in_array($locationId, $this->visibleLocationIds($user), true);
    }

    /** Fresh active creation context; the legacy default is only used without an actor. */
    public function currentLocationId(?User $user): int
    {
        if ($user) {
            $context = app(LocationContext::class)->forUser($user);
            if ($context['current'] !== null) {
                return $context['current'];
            }

            throw new AuthorizationException('Kein aktiver berechtigter Standort für neue Datensätze.');
        }

        return (int) Location::default()->getKey();
    }

    public function forget(?User $user = null): void
    {
        if ($user) {
            unset($this->memo[$user->getKey()]);
        } else {
            $this->memo = [];
        }
    }

    /** @return array<int, string> */
    private function load(User $user): array
    {
        $roles = DB::table('x_location_user')->where('user_id', $user->getKey())->pluck('role', 'location_id')
            ->mapWithKeys(fn ($role, $id) => [(int) $id => (string) $role])->all();

        $own = $user->getAttribute('ext_location_id');
        if ($own && ! isset($roles[(int) $own])) {
            $roles[(int) $own] = 'member';
        }

        return $roles;
    }
}
