<div
    x-data="{}"
    @booking-detail-open.window="$wire.openBookingDetail($event.detail.bookingId)"
    @day-list-open.window="$wire.openDayList($event.detail.date)"
>
    {{-- Vehicle filter chips --}}
    <div class="flex gap-2 overflow-x-auto px-4 py-3 -mx-4 sm:mx-0 sm:px-0 scrollbar-none">
        @foreach($vehicles as $vehicle)
        <button
            wire:click="toggleVehicle({{ $vehicle->id }})"
            class="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-medium whitespace-nowrap transition-all
                   {{ in_array($vehicle->id, $selectedVehicleIds)
                       ? 'text-white shadow-sm'
                       : 'bg-gray-100 text-gray-500' }}"
            @style([
                "background-color: {$vehicle->color}" => in_array($vehicle->id, $selectedVehicleIds),
            ])
        >
            <span class="w-2 h-2 rounded-full inline-block"
                  style="background-color: {{ in_array($vehicle->id, $selectedVehicleIds) ? 'white' : $vehicle->color }}"></span>
            {{ $vehicle->short_name ?? $vehicle->name }}
        </button>
        @endforeach
    </div>

    {{-- FullCalendar container (wire:ignore prevents Livewire from morphing it) --}}
    <div
        wire:ignore
        id="booking-calendar"
        data-events-url="{{ $eventsUrl }}"
        data-initial-view="{{ $initialView }}"
        class="mt-2 bg-white rounded-2xl border border-fw-grey-light p-2 sm:p-4"
    ></div>

    {{-- Booking detail modal --}}
    @if($showDetailModal && $selectedBookingId)
    <livewire:booking.booking-detail :bookingId="$selectedBookingId" :key="'detail-'.$selectedBookingId" />
    @endif

    {{-- Day list modal (mobile) --}}
    @if($showDayList && $dayListDate)
    <livewire:booking.day-list :date="$dayListDate" :key="'daylist-'.$dayListDate" />
    @endif
</div>
