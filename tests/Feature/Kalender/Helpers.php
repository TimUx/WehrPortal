<?php

use App\Models\Module;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
