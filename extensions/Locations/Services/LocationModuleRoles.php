<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use App\Services\ModuleRegistry;
use Carbon\CarbonImmutable;
use Extensions\Locations\Models\Location;
use Extensions\Locations\Models\LocationModuleRole;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LocationModuleRoles
{
    public const SUPPORTED_MODULES = ['anwesenheit', 'mitteilungen'];

    /** Grant vocabulary is deliberately broader than fully certified module adapters. */
    public const STOCK_ROLE = 'bestand';

    public const PERSONAL_ROLE = 'kleiderwart';

    public function grantableModules(): array
    {
        return ['anwesenheit' => 'Anwesenheit', 'mitteilungen' => 'Mitteilungen', 'kleiderkammer' => 'Kleiderkammer – getrennte Teilrechte'];
    }

    public function grantableRoles(string $module): array
    {
        return $module === 'kleiderkammer'
            ? [self::STOCK_ROLE => 'Nur Mengenbestand: ansehen und Stückzahl ändern',
                self::PERSONAL_ROLE => 'Personenprofil und standortbezogene Ausgaben']
            : collect(app(ModuleRegistry::class)->get($module)['roles'] ?? [])->mapWithKeys(fn ($role) => [$role => $role])->all();
    }

    /** Only modules with a completed target-aware role adapter may be activated. */
    public function configuredModules(): array
    {
        $modules = config('locations.scoped_role_modules', []);
        if (! is_array($modules) || ! array_is_list($modules)
            || array_diff($modules, self::SUPPORTED_MODULES) !== []
            || ($modules !== [] && ! config('locations.enabled'))
            || (in_array('anwesenheit', $modules, true) && ! config('anwesenheit.enabled'))) {
            throw new InvalidArgumentException('Scoped location roles require Locations and each selected adapter; only anwesenheit and mitteilungen are supported.');
        }

        return $modules;
    }

    public function enabled(string $module): bool
    {
        return in_array($module, $this->configuredModules(), true);
    }

    public function isGlobalAdmin(User $user): bool
    {
        return DB::table('users')->where('id', $user->id)->where('is_active', true)->where('is_admin', true)->exists();
    }

    public function assertMemberDeletable(User $user): void
    {
        if (Schema::hasTable('x_leadership_assignments')
            && DB::table('x_leadership_assignments')->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)->orWhere('granted_by', $user->id)
                ->orWhere('approved_by', $user->id)->orWhere('revoked_by', $user->id))->exists()) {
            throw ValidationException::withMessages([
                'user' => 'Diese Person ist im unveränderlichen Verlauf der Führungsprofile referenziert. Bitte stattdessen den Status „inaktiv“ oder „ausgetreten“ setzen. Historische Vergaben werden nicht übertragen.',
            ]);
        }
        if (Schema::hasTable('x_location_data_assignments')
            && DB::table('x_location_data_assignments')->where('assigned_by', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user' => 'Diese Person ist im unveränderlichen Verlauf der Standort-Datenzuordnung referenziert. Bitte stattdessen den Status „inaktiv“ oder „ausgetreten“ setzen. Historische Zuordnungen werden nicht übertragen.',
            ]);
        }
        if (Schema::hasTable('x_location_module_roles')
            && DB::table('x_location_module_roles')->where(fn (Builder $q) => $q
                ->where('user_id', $user->id)->orWhere('granted_by', $user->id)->orWhere('revoked_by', $user->id))->exists()) {
            throw ValidationException::withMessages([
                'user' => 'Diese Person ist im unveränderlichen Verlauf der Standort-Modulrollen referenziert. Bitte stattdessen den Status „inaktiv“ oder „ausgetreten“ setzen. Diese Rollen und historischen Verweise werden nicht übertragen.',
            ]);
        }
    }

    private function activeModule(string $module): bool
    {
        return app(ModuleRegistry::class)->has($module)
            && DB::table('modules')->where('key', $module)->where('is_active', true)->exists();
    }

    public function eligible(User $user, string $module): bool
    {
        return $this->activeModule($module) && DB::table('users')->where('id', $user->id)->where('is_active', true)->exists();
    }

    public function canAccessLocation(User $user, ?int $locationId): bool
    {
        return $locationId !== null && DB::table('x_locations')->where('id', $locationId)->where('is_active', true)->exists()
            && DB::table('users')->where('id', $user->id)->where('is_active', true)
                ->where(fn (Builder $q) => $q->where('ext_location_id', $locationId)
                    ->orWhereExists(fn (Builder $m) => $m->selectRaw('1')->from('x_location_user')
                        ->whereColumn('x_location_user.user_id', 'users.id')->where('location_id', $locationId)))->exists();
    }

    /** No model relations, session, or memoized membership may establish authority. */
    private function live(User $user, string $module): Builder
    {
        return DB::table('x_location_module_roles as grants')
            ->join('users as member', 'member.id', '=', 'grants.user_id')
            ->join('x_locations as location', 'location.id', '=', 'grants.location_id')
            ->where('grants.user_id', $user->id)->where('grants.module', $module)
            ->where('member.is_active', true)->where('location.is_active', true)
            ->whereNull('grants.revoked_at')->where('grants.granted_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('grants.expires_at')->orWhere('grants.expires_at', '>', now()))
            ->where(fn (Builder $q) => $q->whereColumn('member.ext_location_id', 'grants.location_id')
                ->orWhereExists(fn (Builder $membership) => $membership->selectRaw('1')->from('x_location_user as membership')
                    ->whereColumn('membership.user_id', 'member.id')->whereColumn('membership.location_id', 'grants.location_id')));
    }

    public function canEnter(User $user, string $module): bool
    {
        return $this->enabled($module) && $this->activeModule($module) && Schema::hasTable('x_location_module_roles')
            && $this->live($user, $module)->exists();
    }

    public function hasRole(User $user, string $module, string $role, ?int $locationId): bool
    {
        if (! $this->enabled($module) || ! $this->activeModule($module)) {
            return false;
        }
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        return $locationId !== null && Schema::hasTable('x_location_module_roles')
            && $this->live($user, $module)->where('grants.location_id', $locationId)->where('grants.role', $role)->exists();
    }

    /** Navigation only. Never use an any-location check to authorize a record. */
    public function hasAnyRole(User $user, string $module, string $role): bool
    {
        return $this->enabled($module) && $this->activeModule($module)
            && ($this->isGlobalAdmin($user) || (Schema::hasTable('x_location_module_roles')
                && $this->live($user, $module)->where('grants.role', $role)->exists()));
    }

    /** @return list<int> */
    public function roleLocationIds(User $user, string $module, string $role): array
    {
        if (! $this->enabled($module) || ! $this->activeModule($module) || ! Schema::hasTable('x_location_module_roles')) {
            return [];
        }

        return $this->live($user, $module)->where('grants.role', $role)->pluck('grants.location_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Capability grants never feed the module-wide User role hooks. */
    public function capabilityLocationIds(User $user, string $module, string $role): array
    {
        if ($module !== 'kleiderkammer' || ! in_array($role, [self::STOCK_ROLE, self::PERSONAL_ROLE], true)
            || ! config('locations.enabled')
            || ! config($role === self::STOCK_ROLE ? 'locations.clothing_stock_enabled' : 'locations.clothing_personal_enabled')
            || ! $this->activeModule($module)) {
            return [];
        }

        return $this->live($user, $module)->where('grants.role', $role)->pluck('grants.location_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    public function grant(User $by, int $locationId, int $userId, string $module, ?string $role, string $reason, ?string $expiresAt = null): LocationModuleRole
    {
        return DB::transaction(function () use ($by, $locationId, $userId, $module, $role, $reason, $expiresAt) {
            $this->authorize($by);
            $reason = trim($reason);
            Validator::make(['reason' => $reason, 'expires_at' => $expiresAt], [
                'reason' => ['required', 'string', 'max:500'],
                'expires_at' => ['nullable', 'date', 'after:now'],
            ])->validate();
            $definition = app(ModuleRegistry::class)->get($module);
            if (! array_key_exists($module, $this->grantableModules()) || ! $definition
                || ($module === 'kleiderkammer' && ! in_array($role, [self::STOCK_ROLE, self::PERSONAL_ROLE], true))
                || ($role !== null && ! array_key_exists($role, $this->grantableRoles($module)))) {
                throw ValidationException::withMessages(['module' => 'Nur vorbereitete Rollen: Anwesenheit, Mitteilungen oder getrennte Kleiderkammer-Teilrechte.']);
            }
            $location = Location::query()->lockForUpdate()->find($locationId);
            $member = User::withoutGlobalScopes()->lockForUpdate()->find($userId);
            $membership = DB::table('x_location_user')->where('location_id', $locationId)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $location?->getAttribute('is_active') || ! $member?->is_active || ! $this->activeModule($module)
                || ((int) $member->getAttribute('ext_location_id') !== $locationId && ! $membership)) {
                throw ValidationException::withMessages(['user_id' => 'Aktives Modul, aktiver Standort und aktives Mitglied dieses Standorts erforderlich.']);
            }
            if (LocationModuleRole::where('location_id', $locationId)->where('user_id', $userId)->where('module', $module)
                ->when($module === 'kleiderkammer', fn ($q) => $q->where('role', $role))->whereNull('revoked_at')->exists()) {
                throw ValidationException::withMessages(['module' => 'Bestehende Zuweisung zuerst ausdrücklich entziehen (auch nach Ablauf).']);
            }

            return LocationModuleRole::create([
                'location_id' => $locationId, 'user_id' => $userId, 'module' => $module, 'role' => $role,
                'granted_by' => $by->id, 'granted_at' => now(), 'reason' => $reason,
                'expires_at' => $expiresAt === null ? null : CarbonImmutable::parse($expiresAt)->utc(),
            ]);
        });
    }

    public function revoke(User $by, int $assignmentId, int $locationId, string $reason): void
    {
        DB::transaction(function () use ($by, $assignmentId, $locationId, $reason) {
            $this->authorize($by);
            $reason = trim($reason);
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']])->validate();
            $grant = LocationModuleRole::where('location_id', $locationId)->lockForUpdate()->findOrFail($assignmentId);
            if ($grant->getAttribute('revoked_at') !== null) {
                throw ValidationException::withMessages(['reason' => 'Diese Zuweisung wurde bereits entzogen.']);
            }
            $grant->update(['revoked_by' => $by->id, 'revoked_at' => now(), 'revoke_reason' => $reason, 'revoked_source' => 'admin']);
        });
    }

    private function authorize(User $by): void
    {
        $actor = DB::table('users')->where('id', $by->id)->lockForUpdate()->first();
        abort_unless($actor?->is_active && $actor->is_admin, 403);
    }
}
