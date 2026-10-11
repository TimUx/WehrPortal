<?php

namespace Modules\Fahrzeugbuchung\src\Livewire\Calendar;

use Livewire\Component;
use Modules\Fahrzeugbuchung\src\Models\Vehicle;

class BookingCalendar extends Component
{
    public array $selectedVehicleIds = [];

    public ?int $selectedBookingId = null;

    public bool $showDetailModal = false;

    public bool $showDayList = false;

    public string $dayListDate = '';

    public function mount(): void
    {
        // Default: all vehicles selected
        $this->selectedVehicleIds = Vehicle::active()->pluck('id')->toArray();
    }

    public function updatedSelectedVehicleIds(): void
    {
        $this->dispatch('calendar-filter-changed', vehicleIds: $this->selectedVehicleIds);
    }

    public function toggleVehicle(int $vehicleId): void
    {
        if (in_array($vehicleId, $this->selectedVehicleIds)) {
            $this->selectedVehicleIds = array_values(
                array_filter($this->selectedVehicleIds, fn ($id) => $id !== $vehicleId)
            );
        } else {
            $this->selectedVehicleIds[] = $vehicleId;
        }

        $this->dispatch('calendar-filter-changed', vehicleIds: $this->selectedVehicleIds);
    }

    public function openBookingDetail(int $bookingId): void
    {
        $this->selectedBookingId = $bookingId;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedBookingId = null;
    }

    public function openDayList(string $date): void
    {
        $this->dayListDate = $date;
        $this->showDayList = true;
    }

    public function closeDayList(): void
    {
        $this->showDayList = false;
    }

    public function refreshCalendar(): void
    {
        $this->dispatch('calendar-refresh');
    }

    public function render()
    {
        $vehicles = Vehicle::active()->get();

        return view('fahrzeugbuchung::livewire.calendar.booking-calendar', [
            'vehicles' => $vehicles,
            'eventsUrl' => route('fahrzeugbuchung.bookings.events'),
            'initialView' => auth()->user()->calendarView(),
        ]);
    }
}
