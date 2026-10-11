<?php

namespace Modules\Fahrzeugbuchung\src\Services;

use App\Services\VehicleBlockRegistry;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class BookingService
{
    public function checkAvailability(
        int $vehicleId,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $excludeBookingId = null
    ): array {
        // Werkstatt and other downtimes (Fahrzeugverwaltung): not bookable at all, no decision
        if ($blocked = app(VehicleBlockRegistry::class)->reason($vehicleId, $startsAt, $endsAt)) {
            return ['available' => false, 'conflict' => null, 'blocked' => $blocked, 'alternatives' => $this->findAlternatives($vehicleId, $startsAt, $endsAt)];
        }

        $query = Booking::active()
            ->where('vehicle_id', $vehicleId)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($excludeBookingId) {
            $query->where('id', '!=', $excludeBookingId);
        }

        $conflicting = $query->with('user')->first();

        if ($conflicting) {
            return [
                'available' => false,
                'conflict' => $conflicting,
                'blocked' => null,
                'alternatives' => $this->findAlternatives($vehicleId, $startsAt, $endsAt),
            ];
        }

        return ['available' => true, 'conflict' => null, 'blocked' => null, 'alternatives' => collect()];
    }

    public function create(array $data, int $userId): Booking
    {
        if ($data['starts_at'] >= $data['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['Die Endzeit muss nach der Startzeit liegen.'],
            ]);
        }
        $this->assertNotBlocked((int) $data['vehicle_id'], Carbon::parse($data['starts_at']), Carbon::parse($data['ends_at']));

        try {
            return Booking::create([
                'vehicle_id' => $data['vehicle_id'],
                'user_id' => $userId,
                'group_id' => $data['group_id'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'purpose' => $data['purpose'],
                'destination' => $data['destination'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
        } catch (QueryException $e) {
            if ($this->isExclusionViolation($e)) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['Das Fahrzeug ist in diesem Zeitraum bereits gebucht.'],
                ]);
            }
            throw $e;
        }
    }

    public function update(Booking $booking, array $data): Booking
    {
        // Moving into a downtime is refused; ending early or other changes inside one are fine
        $vehicleId = (int) ($data['vehicle_id'] ?? $booking->vehicle_id);
        $starts = $data['starts_at'] ?? $booking->starts_at;
        $ends = $data['ends_at'] ?? $booking->ends_at;
        if ($vehicleId !== $booking->vehicle_id || $starts < $booking->starts_at || $ends > $booking->ends_at) {
            $this->assertNotBlocked($vehicleId, Carbon::parse($starts), Carbon::parse($ends));
        }

        try {
            $booking->update($data);
        } catch (QueryException $e) {
            if ($this->isExclusionViolation($e)) {
                throw ValidationException::withMessages([
                    'vehicle_id' => ['Das Fahrzeug ist in diesem Zeitraum bereits gebucht.'],
                ]);
            }
            throw $e;
        }

        app(DecisionService::class)->releaseSlotsFreedBy($booking);

        return $booking->fresh();
    }

    public function cancel(Booking $booking): void
    {
        $booking->update(['cancelled_at' => now()]);

        if ($booking->isPending() && $booking->decision) {
            app(DecisionService::class)->withdraw($booking->decision);

            return;
        }

        app(DecisionService::class)->releaseSlotsFreedBy($booking);
    }

    /** Vehicle is back early: the booking ends now and the rest of the slot is free again */
    public function endEarly(Booking $booking): Booking
    {
        $endsAt = now()->startOfMinute();
        if ($endsAt->lte($booking->starts_at)) {
            $endsAt = now();
        }

        return $this->update($booking, ['ends_at' => $endsAt]);
    }

    /** A vehicle in the Werkstatt (or another downtime) can't be booked in that time */
    public function assertNotBlocked(int $vehicleId, Carbon $startsAt, Carbon $endsAt): void
    {
        if ($reason = app(VehicleBlockRegistry::class)->reason($vehicleId, $startsAt, $endsAt)) {
            throw ValidationException::withMessages([
                'vehicle_id' => ['Das Fahrzeug ist in diesem Zeitraum nicht verfügbar: '.$reason.'.'],
            ]);
        }
    }

    private function findAlternatives(int $excludeVehicleId, Carbon $startsAt, Carbon $endsAt): Collection
    {
        $conflictingVehicleIds = Booking::active()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->pluck('vehicle_id')
            ->merge(app(VehicleBlockRegistry::class)->between($startsAt, $endsAt)->pluck('vehicle_id'));

        return Vehicle::active()
            ->where('id', '!=', $excludeVehicleId)
            ->whereNotIn('id', $conflictingVehicleIds)
            ->get();
    }

    private function isExclusionViolation(QueryException $e): bool
    {
        // SQLSTATE 23P01 = exclusion_violation in PostgreSQL
        return $e->getCode() === '23P01'
            || str_contains($e->getMessage(), 'bookings_no_overlap');
    }
}
