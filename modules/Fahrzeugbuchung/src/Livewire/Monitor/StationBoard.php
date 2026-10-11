<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Monitor;

use Illuminate\Support\Str;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

/**
 * Read-only board for the screen at the station. Refreshes itself every minute
 * (wire:poll) and deliberately offers no way to change anything.
 */
class StationBoard extends Component
{
    /** A next booking this close counts as "bald gebucht" */
    private const SOON_MINUTES = 120;

    private const UPCOMING_DAYS = 7;

    public function render()
    {
        $now = now();

        $bookings = Booking::with(['vehicle', 'user'])
            ->visible()
            ->where('ends_at', '>', $now)
            ->where('starts_at', '<', $now->copy()->addDays(self::UPCOMING_DAYS))
            ->orderBy('starts_at')
            ->get();

        $confirmed = $bookings->where('status', Booking::STATUS_CONFIRMED);

        $vehicles = Vehicle::active()->get()->map(function (Vehicle $vehicle) use ($confirmed, $now) {
            $own = $confirmed->where('vehicle_id', $vehicle->id);
            $current = $own->first(fn (Booking $b) => $b->starts_at <= $now);
            $next = $own->first(fn (Booking $b) => $b->starts_at > $now);

            return [
                'vehicle' => $vehicle,
                'current' => $current,
                'next' => $next,
                'state' => match (true) {
                    $current !== null => 'busy',
                    $next && $next->starts_at->diffInMinutes($now, true) <= self::SOON_MINUTES => 'soon',
                    default => 'free',
                },
            ];
        });

        return view('fahrzeugbuchung::livewire.monitor.station-board', [
            'vehicles' => $vehicles,
            'upcoming' => $bookings->take(14),
            'counts' => [
                'free' => $vehicles->where('state', 'free')->count(),
                'soon' => $vehicles->where('state', 'soon')->count(),
                'busy' => $vehicles->where('state', 'busy')->count(),
            ],
            'updatedAt' => $now->copy()->setTimezone('Europe/Berlin'),
        ]);
    }

    /** "Hanna Hydrant" -> "Hanna H." – the screen may be visible to visitors */
    public static function shortName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name));
        $first = array_shift($parts) ?? '';
        $last = array_pop($parts);

        return $last ? $first.' '.Str::upper(Str::substr($last, 0, 1)).'.' : $first;
    }
}
