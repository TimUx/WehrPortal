<?php

namespace Extensions\Locations\Services;

use App\Models\Group;
use App\Models\User;
use App\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\StandardRoleAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StandardRoleAssignments
{
    /**
     * Live assignments and the active flag per Kamerad: permission checks ask several times per
     * Kamerad and lists ask for every Kamerad. forget() runs on every change.
     *
     * @var array<string, mixed>
     */
    private const MEMO_SECONDS = 5;

    private array $memo = [];

    public function __construct(private readonly StandardRoleCatalog $catalog) {}

    public function forget(): void
    {
        $this->memo = [];
    }

    /** $deputy marks a Stellvertretung; any number of them may hold a profile next to its holder */
    public function grant(User $by, int $userId, string $profile, ?int $locationId, ?int $groupId, string $reason, ?string $expiresAt = null, bool $deputy = false): StandardRoleAssignment
    {
        return DB::transaction(function () use ($by, $userId, $profile, $locationId, $groupId, $reason, $expiresAt, $deputy) {
            $members = User::withoutGlobalScopes()->whereIn('id', [$by->id, $userId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorize($by);
            $definition = $this->catalog->get($profile);
            if (! $this->catalog->isAssignable($profile)) {
                throw ValidationException::withMessages(['profile' => 'Dieses Standardrollenprofil ist noch nicht für die Zuweisung freigegeben.']);
            }
            $reason = trim($reason);
            Validator::make(['reason' => $reason, 'expires_at' => $expiresAt], [
                'reason' => ['required', 'string', 'max:500'],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ])->validate();

            $member = $members->get($userId);
            if (! $member?->getAttribute('is_active')) {
                throw ValidationException::withMessages(['user_id' => 'Nur aktive Mitglieder können eine Standardrolle erhalten.']);
            }
            if ($definition['scope'] === 'location' && ($locationId === null || $groupId !== null)) {
                throw ValidationException::withMessages(['scope' => 'Diese Rolle benötigt genau einen Standort.']);
            }
            if ($definition['scope'] === 'group' && ($locationId === null || $groupId === null)) {
                throw ValidationException::withMessages(['scope' => 'Diese Rolle benötigt genau einen Standort und eine passende Abteilung.']);
            }
            if ($definition['scope'] === 'global' && $definition['group'] === null && ($locationId !== null || $groupId !== null)) {
                throw ValidationException::withMessages(['scope' => 'Diese Rolle gilt global und benötigt weder Standort noch Abteilung.']);
            }
            if ($definition['scope'] === 'global' && $definition['group'] !== null && $locationId !== null) {
                throw ValidationException::withMessages(['scope' => 'Diese Rolle gilt standortübergreifend und benötigt keinen Standort.']);
            }

            if ($locationId !== null) {
                $location = Location::query()->lockForUpdate()->find($locationId);
                $isMember = (int) $member->getAttribute('ext_location_id') === $locationId
                    || DB::table('x_location_user')->where('location_id', $locationId)->where('user_id', $userId)->lockForUpdate()->exists();
                if (! $location?->getAttribute('is_active') || ! $isMember) {
                    throw ValidationException::withMessages(['location_id' => 'Das Mitglied muss einem aktiven Standort angehören.']);
                }
            }
            if ($groupId !== null) {
                $group = Group::query()->withoutGlobalScopes()->lockForUpdate()->find($groupId);
                $groupLocationId = $group?->getAttribute('ext_location_id');
                $groupLocationActive = $group && DB::table('x_locations')->where('id', $groupLocationId)->where('is_active', true)->exists();
                if (! $group || ! $groupLocationActive || ($locationId !== null && (int) $groupLocationId !== $locationId)
                    || ($definition['group'] !== null && strtolower((string) $group->getAttribute('name')) !== $definition['group'])) {
                    throw ValidationException::withMessages(['group_id' => 'Die Abteilung passt nicht zum Rollenprofil und Standort.']);
                }
            }

            $existing = StandardRoleAssignment::query()->where('user_id', $userId)->where('profile', $profile)
                ->where('location_id', $locationId)->where('group_id', $groupId)->whereNull('revoked_at')->exists();
            if ($existing) {
                throw ValidationException::withMessages(['profile' => 'Eine aktive Vergabe besteht bereits. Sie muss zuerst ausdrücklich widerrufen werden.']);
            }

            $assignment = StandardRoleAssignment::query()->create([
                'user_id' => $userId,
                'profile' => $profile,
                'location_id' => $locationId,
                'group_id' => $groupId,
                'granted_by' => $by->id,
                'granted_at' => now(),
                'reason' => $reason,
                'expires_at' => $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->utc(),
                // Same profile, same rights: a Stellvertretung differs in name only
                'is_deputy' => $deputy,
            ]);

            app(LocationAccess::class)->forget($member);

            return $assignment;
        });
    }

    public function revoke(User $by, int $assignmentId, string $reason): void
    {
        DB::transaction(function () use ($by, $assignmentId, $reason) {
            $this->authorize($by);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();
            $grant = StandardRoleAssignment::query()->lockForUpdate()->findOrFail($assignmentId);
            if ($grant->revoked_at !== null) {
                throw ValidationException::withMessages(['reason' => 'Diese Zuweisung wurde bereits entzogen.']);
            }
            $grant->update(['revoked_by' => $by->id, 'revoked_at' => now(), 'revoke_reason' => $reason, 'revoked_source' => 'admin']);
            app(LocationAccess::class)->forget(User::withoutGlobalScopes()->findOrFail($grant->user_id));
        });
    }

    public function hasModuleRoleAt(User $user, string $module, string $role, int $locationId): bool
    {
        if (! $this->active($user) || ! $this->activeModule($module) || ! $this->activeLocation($locationId)) {
            return false;
        }
        if ($this->hasGlobalProfile($user, 'gemeindebrandinspektor')) {
            return true;
        }

        return $this->live($user)->contains(function (StandardRoleAssignment $assignment) use ($module, $role, $locationId) {
            $profile = $this->catalog->get($assignment->profile);
            if (! $this->catalog->hasModuleRole($assignment->profile, $module, $role) || $profile['group'] !== null) {
                return false;
            }
            if ($assignment->location_id === null) {
                return $assignment->group_id === null;
            }

            return (int) $assignment->location_id === $locationId && $this->memberAt((int) $assignment->user_id, $locationId);
        });
    }

    /** @return list<int> active locations for a location-wide role profile, independent of module activation */
    public function locationIdsForProfile(User $user, string $profile): array
    {
        if (! $this->active($user)) {
            return [];
        }

        return $this->live($user)
            ->filter(fn (StandardRoleAssignment $assignment) => $assignment->profile === $profile
                && $assignment->location_id !== null && $assignment->group_id === null
                && $this->activeLocation((int) $assignment->location_id)
                && $this->memberAt((int) $user->id, (int) $assignment->location_id))
            ->pluck('location_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public function hasModuleRoleForGroup(User $user, string $module, string $role, int $locationId, int $groupId): bool
    {
        if (! $this->active($user) || ! $this->activeModule($module) || ! $this->activeLocation($locationId)
            || ! ($group = DB::table('groups')->where('id', $groupId)->where('ext_location_id', $locationId)->first(['name']))) {
            return false;
        }

        return $this->live($user)->contains(function (StandardRoleAssignment $assignment) use ($module, $role, $locationId, $groupId, $group) {
            $profile = $this->catalog->get($assignment->profile);
            if (! $this->catalog->hasModuleRole($assignment->profile, $module, $role)
                || ($profile['group'] !== null && strtolower((string) $group->name) !== $profile['group'])
                || ($assignment->group_id !== null && (int) $assignment->group_id !== $groupId)) {
                return false;
            }
            if ($assignment->location_id !== null) {
                return (int) $assignment->location_id === $locationId && $this->memberAt((int) $assignment->user_id, $locationId);
            }

            return $assignment->group_id !== null || $profile['group'] !== null;
        });
    }

    /** @return list<int> */
    public function groupIds(User $user, string $module, array $roles): array
    {
        if (! $this->active($user) || ! $this->activeModule($module)) {
            return [];
        }

        $groupIds = [];
        foreach ($this->live($user) as $assignment) {
            $profile = $this->catalog->get($assignment->profile);
            if (! collect($roles)->contains(fn (string $role) => $this->catalog->hasModuleRole($assignment->profile, $module, $role))
                || $profile['group'] === null
                || ($assignment->location_id !== null && ! $this->memberAt((int) $assignment->user_id, (int) $assignment->location_id))) {
                continue;
            }

            if ($assignment->group_id !== null) {
                $locationId = $this->assignedGroupLocationId($assignment, $profile);
                if ($locationId !== null
                    && ($assignment->location_id === null || $this->memberAt((int) $assignment->user_id, $locationId))) {
                    $groupIds[] = (int) $assignment->group_id;
                }

                continue;
            }

            if ($assignment->location_id === null) {
                $groupIds = [...$groupIds, ...DB::table('groups')->join('x_locations', 'x_locations.id', '=', 'groups.ext_location_id')
                    ->where('x_locations.is_active', true)->whereRaw('lower(groups.name) = ?', [$profile['group']])
                    ->pluck('groups.id')->map(fn ($id) => (int) $id)->all()];
            }
        }

        return array_values(array_unique($groupIds));
    }

    /** @return list<int> */
    public function locationIds(User $user, string $module, array $roles): array
    {
        return $this->assignedLocationIds($user, $module, $roles, true);
    }

    /** @return list<int> locations managed by roles that are not limited to a group */
    public function locationWideIds(User $user, string $module, array $roles): array
    {
        return $this->assignedLocationIds($user, $module, $roles, false);
    }

    /**
     * The Kameraden someone looks after through a module role: null means everyone (admins, the
     * classic module role, a role for the whole Kommune), a list the members of the Standorte where
     * a standard role gives the role. For modules whose records hang on people, not on a Standort.
     *
     * @return list<int>|null
     */
    public function managedMemberIds(User $user, string $module, string $role): ?array
    {
        if ($user->is_admin || $user->hasModuleRole($module, $role)) {
            return null;
        }
        $locations = config('locations.enabled') ? $this->locationWideIds($user, $module, [$role]) : [];

        return $locations === [] ? [] : $this->memberIdsAt($locations);
    }

    /**
     * @param  list<int>  $locationIds
     * @return list<int> active and former members of these Standorte, by main Standort or membership
     */
    public function memberIdsAt(array $locationIds): array
    {
        return DB::table('users')
            ->where(fn (Builder $query) => $query->whereIn('ext_location_id', $locationIds)
                ->orWhereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('x_location_user')
                    ->whereColumn('x_location_user.user_id', 'users.id')->whereIn('location_id', $locationIds)))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @return list<int> */
    private function assignedLocationIds(User $user, string $module, array $roles, bool $includeGroupProfiles): array
    {
        if (! $this->active($user) || ! $this->activeModule($module)) {
            return [];
        }

        $ids = [];
        foreach ($this->live($user) as $assignment) {
            $profile = $this->catalog->get($assignment->profile);
            if ((! $includeGroupProfiles && $profile['group'] !== null)
                || ! collect($roles)->contains(fn (string $role) => $this->catalog->hasModuleRole($assignment->profile, $module, $role))) {
                continue;
            }
            if ($assignment->group_id !== null) {
                if ($assignment->location_id !== null && ! $this->memberAt((int) $user->id, (int) $assignment->location_id)) {
                    continue;
                }
                $locationId = $this->assignedGroupLocationId($assignment, $profile);
                if ($locationId !== null) {
                    $ids[] = $locationId;
                }
            } elseif ($profile['group'] !== null) {
                $ids = [...$ids, ...DB::table('groups')->join('x_locations', 'x_locations.id', '=', 'groups.ext_location_id')
                    ->where('x_locations.is_active', true)->whereRaw('lower(groups.name) = ?', [$profile['group']])
                    ->pluck('groups.ext_location_id')->map(fn ($id) => (int) $id)->all()];
            } elseif ($assignment->location_id !== null) {
                if ($this->memberAt((int) $user->id, (int) $assignment->location_id)) {
                    $ids[] = (int) $assignment->location_id;
                }
            } else {
                $ids = [...$ids, ...DB::table('x_locations')->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all()];
            }
        }

        return array_values(array_unique($ids));
    }

    private function assignedGroupLocationId(StandardRoleAssignment $assignment, array $profile): ?int
    {
        if ($assignment->group_id === null || $profile['group'] === null) {
            return null;
        }

        $locationId = DB::table('groups')
            ->join('x_locations', 'x_locations.id', '=', 'groups.ext_location_id')
            ->where('groups.id', $assignment->group_id)
            ->where('x_locations.is_active', true)
            ->whereRaw('lower(groups.name) = ?', [$profile['group']])
            ->when($assignment->location_id !== null, fn (Builder $query) => $query->where('groups.ext_location_id', $assignment->location_id))
            ->value('groups.ext_location_id');

        return $locationId === null ? null : (int) $locationId;
    }

    public function hasAnyModuleRole(User $user, string $module, string $role): bool
    {
        return $this->hasGlobalModuleRole($user, $module) || ($this->active($user) && $this->activeModule($module) && $this->live($user)->contains(
            fn (StandardRoleAssignment $assignment) => $this->catalog->hasModuleRole($assignment->profile, $module, $role),
        ));
    }

    public function canEnterModule(User $user, string $module): bool
    {
        return $this->hasGlobalModuleRole($user, $module) || ($this->active($user) && $this->activeModule($module) && $this->live($user)->contains(
            fn (StandardRoleAssignment $assignment) => ($this->catalog->get($assignment->profile)['modules'][$module] ?? []) !== [],
        ));
    }

    public function hasGlobalModuleRole(User $user, string $module, ?string $role = null): bool
    {
        if (! $this->activeModule($module) || ! $this->active($user)) {
            return false;
        }

        return $this->live($user)->contains(function (StandardRoleAssignment $assignment) use ($module, $role) {
            if ($assignment->location_id !== null || $assignment->group_id !== null) {
                return false;
            }
            if ($assignment->profile === 'gemeindebrandinspektor') {
                return true;
            }

            return $role !== null && $this->catalog->get($assignment->profile)['group'] === null
                && $this->catalog->hasModuleRole($assignment->profile, $module, $role);
        });
    }

    /** GBI/SBI spans every active module; other global profiles grant only their mapped module roles. */
    public function hasGlobalProfile(User $user, string $profile): bool
    {
        return $this->active($user) && $this->live($user)->contains(
            fn (StandardRoleAssignment $assignment) => $assignment->profile === $profile
                && $assignment->location_id === null && $assignment->group_id === null,
        );
    }

    /**
     * Kameraden who hold a module role through a standard role naming it: at this Standort or
     * for the whole Gemeinde; without a Standort only the latter. For recipients of mails and
     * invitations. GBI/SBI is left out on purpose: access to everything is not a duty in everything.
     *
     * @return list<int>
     */
    public function userIdsWithModuleRole(string $module, string $role, ?int $locationId = null): array
    {
        $profiles = collect($this->catalog->all())
            ->filter(fn (array $definition, string $profile) => $definition['group'] === null && $this->catalog->hasModuleRole($profile, $module, $role))
            ->keys()->all();
        if ($profiles === [] || ! Schema::hasTable('x_standard_role_assignments') || ! $this->activeModule($module)) {
            return [];
        }

        return DB::table('x_standard_role_assignments as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->leftJoin('x_locations', 'x_locations.id', '=', 'assignments.location_id')
            ->whereIn('assignments.profile', $profiles)->whereNull('assignments.group_id')
            ->whereNull('assignments.revoked_at')->where('assignments.granted_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now()))
            ->where('users.is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('assignments.location_id')
                ->when($locationId !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query
                    ->where('assignments.location_id', $locationId)->where('x_locations.is_active', true)
                    // The role only counts while the Kamerad still belongs to the Standort
                    ->where(fn (Builder $query) => $query->where('users.ext_location_id', $locationId)
                        ->orWhereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('x_location_user')
                            ->whereColumn('x_location_user.user_id', 'users.id')->where('x_location_user.location_id', $locationId))))))
            ->pluck('assignments.user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> */
    public function activeGlobalProfileUserIds(string $profile): array
    {
        if (! Schema::hasTable('x_standard_role_assignments')) {
            return [];
        }

        return DB::table('x_standard_role_assignments as assignments')
            ->join('users', 'users.id', '=', 'assignments.user_id')
            ->where('assignments.profile', $profile)
            ->whereNull('assignments.location_id')->whereNull('assignments.group_id')
            ->whereNull('assignments.revoked_at')->where('assignments.granted_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('assignments.expires_at')->orWhere('assignments.expires_at', '>', now()))
            ->where('users.is_active', true)->pluck('assignments.user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Loads the role answers for many Kameraden with two queries, for pages that check each of a
     * list (the audience of a Termin) instead of two queries per Kamerad.
     *
     * @param  iterable<int>  $userIds
     */
    public function preload(iterable $userIds): void
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty() || ! Schema::hasTable('x_standard_role_assignments')) {
            return;
        }

        $now = now();
        $live = StandardRoleAssignment::query()->whereIn('user_id', $ids)->whereNull('revoked_at')
            ->where('granted_at', '<=', $now)->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->get()->groupBy('user_id');
        $active = DB::table('users')->whereIn('id', $ids)->where('is_active', true)->pluck('id')->flip();
        foreach ($ids as $id) {
            $this->memo['live:'.$id] = [$now->timestamp, $live->get($id, new EloquentCollection)->values()];
            $this->memo['active:'.$id] = $active->has($id);
        }
    }

    /** @return Collection<int, StandardRoleAssignment> */
    private function live(User $user): Collection
    {
        if (! Schema::hasTable('x_standard_role_assignments')) {
            return collect();
        }

        // Remembered for a few seconds only, so expiry dates (and a moved clock in tests) still count
        [$loadedAt, $assignments] = $this->memo['live:'.$user->id] ?? [null, null];
        $age = $loadedAt === null ? null : now()->timestamp - $loadedAt;
        if ($age !== null && $age >= 0 && $age <= self::MEMO_SECONDS) {
            return $assignments;
        }

        $assignments = StandardRoleAssignment::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->where('granted_at', '<=', now())->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        $this->memo['live:'.$user->id] = [now()->timestamp, $assignments];

        return $assignments;
    }

    private function active(User $user): bool
    {
        return $this->memo['active:'.$user->id] ??= DB::table('users')->where('id', $user->id)->where('is_active', true)->exists();
    }

    private function activeLocation(int $locationId): bool
    {
        return DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists();
    }

    private function activeModule(string $module): bool
    {
        return app(ModuleRegistry::class)->has($module) && app(ModuleRegistry::class)->isActive($module);
    }

    private function memberAt(int $userId, int $locationId): bool
    {
        return DB::table('users')->where('id', $userId)->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('ext_location_id', $locationId)
                ->orWhereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('x_location_user')
                    ->whereColumn('x_location_user.user_id', 'users.id')->where('location_id', $locationId)))->exists();
    }

    private function authorize(User $by): void
    {
        $actor = DB::table('users')->where('id', $by->id)->lockForUpdate()->first();
        abort_unless($actor?->is_active && $actor->is_admin, 403);
    }
}
