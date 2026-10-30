<?php

namespace App\Services;

use App\Models\User;
use Extensions\Locations\Models\StandardRoleAssignment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

class LocationNotifications
{
    /** @return EloquentCollection<int, User> */
    public function leadership(?int $locationId): EloquentCollection
    {
        if (! config('locations.enabled') || ! $locationId || ! Schema::hasTable('x_standard_role_assignments')) {
            return new EloquentCollection;
        }

        $ids = StandardRoleAssignment::query()->where('profile', 'wehrfuehrung')
            ->where('location_id', $locationId)->whereNull('group_id')
            ->whereHas('location', fn ($query) => $query->where('is_active', true))
            ->whereNull('revoked_at')->where('granted_at', '<=', now())
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('user_id');

        return User::withoutGlobalScopes()->active()->whereKey($ids)->get();
    }

    /**
     * Each recipient gets one digest, containing only entries assigned to them.
     * Existing specialist recipients retain their own authorization rules.
     *
     * @param  callable(mixed): ?int  $locationOf
     * @param  callable(Collection): Notification  $notification
     * @param  callable(mixed): Collection  $specialists
     */
    public function digest(Collection $entries, callable $locationOf, callable $notification, callable $specialists): void
    {
        $leaders = [];
        $deliveries = [];
        foreach ($entries as $key => $entry) {
            $locationId = $locationOf($entry);
            $leaders[$locationId ?? 0] ??= $this->leadership($locationId);
            $recipients = $specialists($entry)->toBase()->merge($leaders[$locationId ?? 0])->unique('id');
            foreach ($recipients as $user) {
                $deliveries[$user->id] ??= ['user' => $user, 'entries' => collect()];
                $deliveries[$user->id]['entries']->put($key, $entry);
            }
        }

        foreach ($deliveries as $delivery) {
            try {
                $delivery['user']->notify($notification($delivery['entries']->values()));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
