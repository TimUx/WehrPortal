<?php

namespace Modules\Ausruestung\src\Services;

use App\Models\User;
use App\Services\LocationNotifications;
use Extensions\Locations\Models\StandardRoleAssignment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Who looks after the equipment, and sending to them without letting a mail error undo anything */
class AusruestungMailer
{
    /** Gerätewarte, or the admins if nobody holds the role */
    public function managers(): Collection
    {
        $wardens = User::active()
            ->whereHas('moduleAccess', fn ($q) => $q->where('module', 'ausruestung')->where('role', 'geraetewart'))
            ->get();

        return $wardens->isNotEmpty() ? $wardens : User::active()->where('is_admin', true)->get();
    }

    public function toManagers(Notification $notification, ?User $except = null): void
    {
        $this->managers()
            ->reject(fn (User $manager) => $except && $manager->is($except))
            ->each(fn (User $manager) => $this->send($manager, $notification));
    }

    public function toLocationManagers(Notification $notification, int $locationId, ?User $except = null): void
    {
        $this->locationManagers($locationId)
            ->reject(fn (User $manager) => $except && $manager->is($except))
            ->each(fn (User $manager) => $this->send($manager, $notification));
    }

    /** @return Collection<int, User> */
    private function locationManagers(int $locationId): Collection
    {
        if (! config('locations.enabled') || ! Schema::hasTable('x_standard_role_assignments')) {
            return new Collection;
        }

        $userIds = StandardRoleAssignment::query()
            ->where('profile', 'geraetewart')
            ->where('location_id', $locationId)
            ->whereNull('group_id')
            ->whereNull('revoked_at')
            ->where('granted_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');

        return User::withoutGlobalScopes()->active()->whereKey($userIds)->get()
            ->filter(fn (User $user) => EquipmentService::canManageLocation($user, $locationId))
            ->merge(app(LocationNotifications::class)->leadership($locationId))
            ->unique('id')
            ->values();
    }

    public function send(User $user, Notification $notification): void
    {
        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
