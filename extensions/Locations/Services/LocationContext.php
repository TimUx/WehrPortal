<?php

namespace Extensions\Locations\Services;

use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fresh creation defaults, not permission or data filters. The primary Standort still counts as membership.
 */
class LocationContext
{
    /** @return array{locations: Collection<int, Location>, current: ?int, globalAdmin: bool} */
    public function forUser(?User $user): array
    {
        $empty = ['locations' => new Collection, 'current' => null, 'globalAdmin' => false];
        if (! config('locations.enabled') || ! $user) {
            return $empty;
        }

        // Do not trust a loaded User or LocationAccess's request-local membership memo after revocation.
        $actor = DB::table('users')->where('id', $user->getKey())->first(['is_active', 'is_admin', 'ext_location_id']);
        if (! $actor || ! $actor->is_active) {
            return $empty;
        }

        $locations = Location::query()->active()
            ->when(! $actor->is_admin, fn ($query) => $query->where(fn ($query) => $query
                ->where('id', $actor->ext_location_id)
                ->orWhereIn('id', DB::table('x_location_user')->where('user_id', $user->getKey())->select('location_id'))))
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        $chosen = request()->hasSession() ? (int) request()->session()->get('ext_location_id') : 0;
        if ($chosen && ! $locations->contains('id', $chosen)) {
            request()->session()->forget('ext_location_id');
            $chosen = 0;
        }

        $current = $chosen ?: ($locations->contains('id', $actor->ext_location_id)
            ? (int) $actor->ext_location_id
            : $locations->first()?->getKey());

        return ['locations' => $locations, 'current' => $current, 'globalAdmin' => (bool) $actor->is_admin];
    }
}
