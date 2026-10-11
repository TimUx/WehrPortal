<?php

namespace Modules\Fahrzeugbuchung\src\Services;

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
                'alternatives' => $this->findAlternatives($vehicleId, $startsAt, $endsAt),
            ];
        }

        return ['available' => true, 'conflict' => null, 'alternatives' => collect()];
    }

    public function create(array $data, int $userId): Booking
    {
        if ($data['starts_at'] >= $data['ends_at']) {
            throw ValidationException::withMessages([
                'ends_at' => ['Die Endzeit muss nach der Startzeit liegen.'],
            ]);
        }

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

    private function findAlternatives(int $excludeVehicleId, Carbon $startsAt, Carbon $endsAt): Collection
    {
        $conflictingVehicleIds = Booking::active()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->pluck('vehicle_id');

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
