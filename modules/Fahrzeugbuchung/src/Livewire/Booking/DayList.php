<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Booking;

use Carbon\Carbon;
use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Booking;

class DayList extends Component
{
    public string $date;

    public function openNewBooking(): void
    {
        $this->dispatch('close-day-list');
        $this->dispatch('open-booking-form', date: $this->date, vehicleId: null);
    }

    public function openDetail(int $bookingId): void
    {
        $this->dispatch('close-day-list');
        $this->dispatch('booking-detail-open', bookingId: $bookingId);
    }

    public function render()
    {
        $day = Carbon::parse($this->date, 'Europe/Berlin');

        $bookings = Booking::with(['vehicle', 'user', 'group'])
            ->visible()
            ->where('starts_at', '<', $day->copy()->endOfDay()->utc())
            ->where('ends_at', '>', $day->copy()->startOfDay()->utc())
            ->orderBy('starts_at')
            ->get();

        return view('fahrzeugbuchung::livewire.booking.day-list', [
            'day' => $day,
            'bookings' => $bookings,
        ]);
    }
}
