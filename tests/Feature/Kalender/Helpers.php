<?php

use App\Models\Module;
use App\Models\User;
use Extensions\Locations\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Kalender\src\Models\Event;
use Modules\Kalender\src\Services\EventService;

function activateKalender(): void
{
    Module::where('key', 'kalender')->update(['is_active' => true]);
    Cache::forget('modules_active_keys');
}

function kalenderUser(?string $role = null, array $attributes = []): User
{
    $user = User::factory()->create($attributes + ['phone' => null]);
    if (config('locations.enabled') && Schema::hasTable('x_locations')) {
        $locationId = $user->getAttribute('ext_location_id') ?: Location::default()->getKey();
        $user->forceFill(['ext_location_id' => $locationId])->save();
        DB::table('x_location_user')->insertOrIgnore(['location_id' => $locationId, 'user_id' => $user->id, 'role' => 'member']);
    }
    $user->moduleAccess()->create(['module' => 'kalender', 'role' => $role]);

    return $user;
}

/** An event in Berlin time, e.g. kalenderEvent('2027-06-15 19:00', '21:00') */
function kalenderEvent(string $start, string $endTime = '21:00', array $attributes = [], array $groups = []): Event
{
    $from = Carbon::parse($start, 'Europe/Berlin');

    return app(EventService::class)->create($attributes + [
        'title' => 'Übungsdienst',
        'type' => 'uebung',
        'starts_at' => $from,
        'ends_at' => Carbon::parse($from->format('Y-m-d').' '.$endTime, 'Europe/Berlin'),
    ], $groups, null)->first();
}
