<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Modules\Mitteilungen\src\Models\Announcement;
use Modules\Mitteilungen\src\Services\AnnouncementService;

class ScopedAnnouncementService extends AnnouncementService
{
    public function mail(Announcement $announcement, ?array $only = null, ?EloquentCollection $to = null, bool $reminder = false, ?User $by = null): int
    {
        $this->assertActionAuthority($announcement, $by);
        if ($to !== null) {
            $requested = $to->modelKeys();
            $to = $this->recipients($announcement)->filter(fn (User $recipient) => in_array($recipient->id, $requested, true))->values();
        }

        return parent::mail($announcement, $only, $to, $reminder, $by);
    }

    public function readerLocationIds(User $user): array
    {
        $current = User::withoutGlobalScopes()->find($user->id);

        return $current ? parent::readerLocationIds($current) : [];
    }

    public function assertReaderAuthority(Announcement $announcement, User $user, bool $currentOnly = false): void
    {
        $current = User::withoutGlobalScopes()->find($user->id);
        abort_unless($current && app(LocationModuleRoles::class)->eligible($current, 'mitteilungen')
            && $current->canUseModule('mitteilungen'), 403);
        $query = Announcement::for($current)->whereKey($announcement->id);
        $currentOnly
            ? $query->current()
            : $query->where(fn (Builder $q) => $q->current()->orWhere('visible_until', '<', now()));
        abort_unless($query->exists(), 403);
        $announcement->refresh();
    }

    public function markAttachmentRead(Announcement $announcement, User $user, bool $isRecipient): void
    {
        if ($isRecipient) {
            $this->markRead($announcement, $user);
        }
    }

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
