<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Booking;

use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Booking;
use Modules\Fahrzeugbuchung\src\Services\BookingService;

class BookingDetail extends Component
{
    public int $bookingId;

    public bool $confirmCancel = false;

    public bool $confirmEndEarly = false;

    public function cancel(BookingService $service): void
    {
        $booking = Booking::findOrFail($this->bookingId);
        $this->authorize('cancel', $booking);

        $service->cancel($booking);

        $this->dispatch('close-detail-modal');
        $this->dispatch('calendar-refresh');
        session()->flash('success', 'Buchung storniert.');
    }

    public function endEarly(BookingService $service): void
    {
        $booking = Booking::findOrFail($this->bookingId);
        $this->authorize('endEarly', $booking);

        $service->endEarly($booking);

        $this->dispatch('close-detail-modal');
        $this->dispatch('calendar-refresh');
        session()->flash('success', 'Fahrzeug zurückgegeben – es ist ab sofort wieder frei.');
    }

    public function editBooking(): void
    {
        $this->dispatch('close-detail-modal');
        $this->dispatch('open-edit-booking', bookingId: $this->bookingId);
    }

    public function render()
    {
        $booking = Booking::with(['vehicle', 'user', 'group'])->findOrFail($this->bookingId);

        return view('fahrzeugbuchung::livewire.booking.booking-detail', [
            'booking' => $booking,
            'canEdit' => auth()->user()?->can('update', $booking) ?? false,
            'canCancel' => auth()->user()?->can('cancel', $booking) ?? false,
            'canEndEarly' => auth()->user()?->can('endEarly', $booking) ?? false,
        ]);
    }
}
