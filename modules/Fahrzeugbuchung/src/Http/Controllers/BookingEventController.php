<?php

namespace Modules\Fahrzeugbuchung\src\Http\Controllers;

use App\Services\VehicleBlockRegistry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class BookingEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $start = $request->query('start')
            ? Carbon::parse($request->query('start'))->utc()
            : now()->startOfMonth();

        $end = $request->query('end')
            ? Carbon::parse($request->query('end'))->utc()
            : now()->endOfMonth();

        $vehicleIds = $request->query('vehicles', []);

        $query = Booking::with(['vehicle', 'user'])
            ->visible()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);

        if (! empty($vehicleIds)) {
            $query->whereIn('vehicle_id', $vehicleIds);
        }

        $events = $query->get()->map(function (Booking $booking) {
            return [
                'id' => $booking->id,
                'title' => ($booking->isPending() ? 'Angefragt: ' : '')
                    .$booking->vehicle->displayName().' – '.$booking->purpose.' · '.$booking->user->name,
                'start' => $booking->starts_at->toIso8601String(),
                'end' => $booking->ends_at->toIso8601String(),
                'color' => $booking->vehicle->color,
                'classNames' => $booking->isPending() ? ['fc-event-pending'] : [],
                'extendedProps' => [
                    'bookingId' => $booking->id,
                    'vehicleId' => $booking->vehicle_id,
                    'vehicleName' => $booking->vehicle->name,
                    'vehicleShort' => $booking->vehicle->displayName(),
                    'vehicleType' => $booking->vehicle->type,
                    'userName' => $booking->user->name,
                    'purpose' => $booking->purpose,
                    'destination' => $booking->destination,
                    'status' => $booking->status,
                ],
            ];
        });

        // Werkstatt and other downtimes from the Fahrzeugverwaltung: grey, not clickable
        $vehicles = Vehicle::get(['id', 'name', 'short_name', 'type'])->keyBy('id');
        $blocks = app(VehicleBlockRegistry::class)->between($start, $end, empty($vehicleIds) ? null : array_map('intval', (array) $vehicleIds))
            ->map(fn (array $block, int $i) => [
                'id' => 'block-'.$block['vehicle_id'].'-'.$i,
                'title' => ($vehicles[$block['vehicle_id']]?->displayName() ?? 'Fahrzeug').' – nicht verfügbar: '.$block['reason'],
                'start' => $block['starts_at']->toIso8601String(),
                'end' => ($block['ends_at'] ?? $end)->toIso8601String(),
                'color' => '#9ca3af',
                'classNames' => ['fc-event-blocked'],
                'extendedProps' => [
                    'blocked' => true, 'vehicleId' => $block['vehicle_id'], 'vehicleName' => $vehicles[$block['vehicle_id']]?->name ?? '',
                    'vehicleShort' => $vehicles[$block['vehicle_id']]?->displayName() ?? '', 'vehicleType' => $vehicles[$block['vehicle_id']]?->type,
                    'purpose' => 'Nicht verfügbar: '.$block['reason'], 'userName' => 'Fahrzeugverwaltung',
                ],
            ]);

        return response()->json($events->concat($blocks)->values());
    }
}
