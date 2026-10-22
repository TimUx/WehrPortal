<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

class ScopedAnnouncementService extends AnnouncementService
{
    protected function isManagementAdmin(User $user): bool
    {
        return app(LocationModuleRoles::class)->eligible($user, 'mitteilungen')
            && app(LocationModuleRoles::class)->isGlobalAdmin($user);
    }

    public function canManageScope(User $user, ?int $locationId): bool
    {
        $roles = app(LocationModuleRoles::class);
        if (! $roles->eligible($user, 'mitteilungen')) {
            return false;
        }
        if ($this->isManagementAdmin($user)) {
            return $locationId === null || Location::whereKey($locationId)->exists();
        }

        return $locationId !== null && $roles->hasRole($user, 'mitteilungen', 'redakteur', $locationId);
    }

    public function canManageAnything(User $user): bool
    {
        return app(LocationModuleRoles::class)->hasAnyRole($user, 'mitteilungen', 'redakteur');
    }

    public function manageableLocationIds(User $user): array
    {
        return $this->isManagementAdmin($user)
            ? Location::query()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : app(LocationModuleRoles::class)->roleLocationIds($user, 'mitteilungen', 'redakteur');
    }

    protected function managementScopeQuery(User $user, Builder $query): Builder
    {
        return $query->whereIn('ext_location_id', $this->manageableLocationIds($user));
    }

    public function canManage(User $user, Announcement $announcement): bool
    {
        $current = $announcement->fresh();

        return $current !== null && parent::canManage($user, $current);
    }

    public function assertActionAuthority(Announcement $announcement, ?User $by = null): void
    {
        abort_unless($by !== null, 403);
        $this->assertCanManage($by, $announcement);
        $announcement->refresh();
    }
}
